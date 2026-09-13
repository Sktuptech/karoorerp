<?php

declare(strict_types=1);

use Karoor\Core\Database;
use Karoor\Core\Helpers;
use Karoor\Core\Response;
use Karoor\Core\Validator;

if (!defined('KAROOR_BOOTSTRAPPED')) { http_response_code(404); exit; }

$hrUser = $auth->requireAuth();
$hrCompany = (int) $hrUser['company_id'];
$hrMethod = Helpers::requestMethod();
$hrResource = $apiAction === 'index' ? 'employees' : $apiAction;
$hrId = isset($apiSegments[1]) && ctype_digit((string) $apiSegments[1]) ? (int) $apiSegments[1] : null;
$hrAction = $hrId === null ? null : ($apiSegments[2] ?? null);

function karoor_hr_input(): array
{
    try { return Helpers::requestData(); } catch (RuntimeException) { Response::error('The request body is invalid.', [], 400); }
}

function karoor_hr_validate(array $input, array $rules): array
{
    $validator = new Validator($input, $rules);
    if (!$validator->passes()) { Response::validation($validator->errors()); }
    return $validator->validated();
}

function karoor_hr_employee(Database $db, int $id, int $company, bool $lock = false): array
{
    if ($lock) {
        $locked = $db->fetchOne('SELECT * FROM employees WHERE id=:id AND company_id=:company AND deleted_at IS NULL FOR UPDATE', ['id'=>$id,'company'=>$company]);
        if ($locked === null) { throw new DomainException('The employee was not found.'); }
        return $locked;
    }
    $row = $db->fetchOne(
        'SELECT e.*,d.name department_name,b.name branch_name FROM employees e LEFT JOIN departments d ON d.id=e.department_id LEFT JOIN branches b ON b.id=e.branch_id WHERE e.id=:id AND e.company_id=:company AND e.deleted_at IS NULL' . ($lock ? ' FOR UPDATE' : ''),
        ['id' => $id, 'company' => $company]
    );
    if ($row === null) { throw new DomainException('The employee was not found.'); }
    return $row;
}

function karoor_hr_photo(array $config): ?string
{
    if (!isset($_FILES['photo']) || !is_array($_FILES['photo']) || ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { return null; }
    $file = $_FILES['photo'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) { throw new DomainException('The employee photo upload failed.'); }
    if ((int) $file['size'] > (int) $config['security']['max_upload_bytes']) { throw new DomainException('The employee photo exceeds the upload size limit.'); }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $types = ['image/jpeg' => ['jpg' => 'jpg', 'jpeg' => 'jpg'], 'image/png' => ['png' => 'png'], 'image/webp' => ['webp' => 'webp']];
    if (!isset($types[$mime][$extension])) { throw new DomainException('Employee photos must be valid JPG, PNG, or WebP files.'); }
    $directory = rtrim((string) $config['paths']['uploads'], '/') . '/employees';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) { throw new RuntimeException('Unable to prepare the employee photo directory.'); }
    $name = Helpers::uuid() . '.' . $types[$mime][$extension];
    if (!move_uploaded_file((string) $file['tmp_name'], $directory . '/' . $name)) { throw new RuntimeException('Unable to store the employee photo.'); }
    return 'uploads/employees/' . $name;
}

function karoor_hr_datetime(?string $value, string $field): ?string
{
    if ($value === null || $value === '') { return null; }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value) ?: DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
    if ($date === false) { Response::validation([$field => ['A valid date and time is required.']]); }
    return $date->format('Y-m-d H:i:s');
}

if ($hrResource === 'departments') {
    $auth->requirePermission('hr.employee');
    if ($hrMethod === 'GET') {
        Response::success($database->fetchAll('SELECT d.*,p.name parent_name,COUNT(e.id) employee_count FROM departments d LEFT JOIN departments p ON p.id=d.parent_id LEFT JOIN employees e ON e.department_id=d.id AND e.deleted_at IS NULL WHERE d.company_id=:company AND d.deleted_at IS NULL GROUP BY d.id ORDER BY d.name', ['company' => $hrCompany]));
    }
    if (in_array($hrMethod, ['POST', 'PUT', 'PATCH'], true)) {
        $data = karoor_hr_validate(karoor_hr_input(), ['name'=>'required|string|max_length:150','code'=>'required|string|max_length:30','parent_id'=>'sometimes|nullable|integer|min:1','description'=>'sometimes|nullable|string|max_length:255','is_active'=>'sometimes|boolean']);
        try {
            $id = $database->transaction(function (Database $db) use ($data, $hrId, $hrCompany, $hrUser, $auditLogger): int {
                $old = $hrId === null ? null : $db->fetchOne('SELECT * FROM departments WHERE id=:id AND company_id=:company AND deleted_at IS NULL FOR UPDATE', ['id'=>$hrId,'company'=>$hrCompany]);
                if ($hrId !== null && $old === null) { throw new DomainException('The department was not found.'); }
                if (($data['parent_id'] ?? null) !== null) {
                    if ((int) $data['parent_id'] === $hrId || $db->fetchOne('SELECT id FROM departments WHERE id=:id AND company_id=:company AND deleted_at IS NULL', ['id'=>(int)$data['parent_id'],'company'=>$hrCompany]) === null) { throw new DomainException('The parent department is unavailable.'); }
                }
                $values=['company_id'=>$hrCompany,'parent_id'=>$data['parent_id']??null,'name'=>$data['name'],'code'=>strtoupper($data['code']),'description'=>$data['description']??null,'is_active'=>$data['is_active']??1];
                if ($hrId === null) { $id=$db->insert('departments',$values); } else { $db->execute('UPDATE departments SET parent_id=:parent,name=:name,code=:code,description=:description,is_active=:active WHERE id=:id',['parent'=>$values['parent_id'],'name'=>$values['name'],'code'=>$values['code'],'description'=>$values['description'],'active'=>$values['is_active'],'id'=>$hrId]); $id=$hrId; }
                $auditLogger->log($hrId===null?'CREATE':'UPDATE','hrm',$id,$old,$values,'departments',(int)$hrUser['id'],$hrCompany);
                return $id;
            });
        } catch (DomainException $e) { Response::error($e->getMessage(), [], 422); }
        Response::success(['id'=>$id], $hrId===null?'Department created.':'Department updated.', [], $hrId===null?201:200);
    }
}

if ($hrResource === 'employees') {
    $auth->requirePermission('hr.employee');
    if ($hrMethod === 'GET') {
        if ($hrId !== null) {
            try { $employee=karoor_hr_employee($database,$hrId,$hrCompany); } catch (DomainException $e) { Response::notFound($e->getMessage()); }
            $employee['recent_attendance']=$database->fetchAll('SELECT * FROM attendance WHERE employee_id=:id ORDER BY attendance_date DESC LIMIT 30',['id'=>$hrId]);
            $employee['leave_requests']=$database->fetchAll('SELECT lr.*,lt.name leave_type FROM leave_requests lr INNER JOIN leave_types lt ON lt.id=lr.leave_type_id WHERE lr.employee_id=:id AND lr.deleted_at IS NULL ORDER BY lr.start_date DESC LIMIT 20',['id'=>$hrId]);
            $employee['payroll']=$database->fetchAll('SELECT id,payroll_month,gross_salary,net_salary,status FROM payroll WHERE employee_id=:id AND deleted_at IS NULL ORDER BY payroll_month DESC LIMIT 12',['id'=>$hrId]);
            Response::success($employee);
        }
        $pagination=Helpers::pagination();$params=['company'=>$hrCompany];$where='e.company_id=:company AND e.deleted_at IS NULL';
        if (isset($_GET['search'])&&trim((string)$_GET['search'])!=='') {$where.=' AND (e.full_name LIKE :search OR e.employee_number LIKE :search OR e.position LIKE :search)';$params['search']='%'.trim((string)$_GET['search']).'%';}
        foreach(['department_id'=>'e.department_id','status'=>'e.status'] as $key=>$column){if(isset($_GET[$key])&&$_GET[$key]!==''){$where.=" AND $column=:$key";$params[$key]=$_GET[$key];}}
        $count=$database->fetchOne('SELECT COUNT(*) total FROM employees e WHERE '.$where,$params);
        $rows=$database->fetchAll('SELECT e.*,d.name department_name,b.name branch_name FROM employees e LEFT JOIN departments d ON d.id=e.department_id LEFT JOIN branches b ON b.id=e.branch_id WHERE '.$where.' ORDER BY e.full_name LIMIT '.$pagination['per_page'].' OFFSET '.$pagination['offset'],$params);
        Response::success($rows,'Employees loaded.',Helpers::paginationMeta((int)($count['total']??0),$pagination['page'],$pagination['per_page']));
    }
    if (in_array($hrMethod,['POST','PUT','PATCH'],true)) {
        $data=karoor_hr_validate(karoor_hr_input(),['employee_number'=>'sometimes|nullable|string|max_length:40','full_name'=>'required|string|max_length:190','phone'=>'sometimes|nullable|string|max_length:50','email'=>'sometimes|nullable|email|max_length:190','address'=>'sometimes|nullable|string|max_length:255','position'=>'required|string|max_length:120','hire_date'=>'required|date:Y-m-d','base_salary'=>'required|numeric|min:0','currency_code'=>'sometimes|string|min_length:3|max_length:3','status'=>'sometimes|in:ACTIVE,ON_LEAVE,SUSPENDED,TERMINATED','department_id'=>'sometimes|nullable|integer|min:1','branch_id'=>'sometimes|nullable|integer|min:1','user_id'=>'sometimes|nullable|integer|min:1','emergency_contact_name'=>'sometimes|nullable|string|max_length:190','emergency_contact_phone'=>'sometimes|nullable|string|max_length:50']);
        try {
            $photo=karoor_hr_photo($config);
            $id=$database->transaction(function(Database $db)use($data,$photo,$hrId,$hrCompany,$hrUser,$auditLogger):int{
                $old=$hrId===null?null:karoor_hr_employee($db,$hrId,$hrCompany,true);
                foreach(['department_id'=>'departments','branch_id'=>'branches','user_id'=>'users'] as $field=>$table){if(($data[$field]??null)!==null&&$db->fetchOne('SELECT id FROM '.Database::quoteIdentifier($table).' WHERE id=:id AND company_id=:company AND deleted_at IS NULL',['id'=>(int)$data[$field],'company'=>$hrCompany])===null)throw new DomainException('A selected employee relationship is unavailable.');}
                $number=trim((string)($data['employee_number']??''));if($number===''){$number='EMP-'.strtoupper(substr(str_replace('-','',Helpers::uuid()),0,10));}
                $values=['company_id'=>$hrCompany,'branch_id'=>$data['branch_id']??null,'department_id'=>$data['department_id']??null,'user_id'=>$data['user_id']??null,'employee_number'=>$number,'full_name'=>$data['full_name'],'phone'=>$data['phone']??null,'email'=>$data['email']??null,'address'=>$data['address']??null,'position'=>$data['position'],'hire_date'=>$data['hire_date'],'base_salary'=>$data['base_salary'],'currency_code'=>strtoupper((string)($data['currency_code']??'ETB')),'status'=>$data['status']??'ACTIVE','photo_path'=>$photo??($old['photo_path']??null),'emergency_contact_name'=>$data['emergency_contact_name']??null,'emergency_contact_phone'=>$data['emergency_contact_phone']??null];
                if($hrId===null){$id=$db->insert('employees',$values);}else{$sets=[];$params=['id'=>$hrId];foreach($values as$key=>$value){if($key==='company_id')continue;$sets[]=Database::quoteIdentifier($key).'=:'.$key;$params[$key]=$value;}$db->execute('UPDATE employees SET '.implode(',',$sets).' WHERE id=:id',$params);$id=$hrId;}
                $auditLogger->log($hrId===null?'CREATE':'UPDATE','hrm',$id,$old,$values,'employees',(int)$hrUser['id'],$hrCompany);return$id;
            });
        }catch(DomainException$e){Response::error($e->getMessage(),[],422);}
        Response::success(karoor_hr_employee($database,$id,$hrCompany),$hrId===null?'Employee created.':'Employee updated.',[],$hrId===null?201:200);
    }
    if($hrMethod==='DELETE'&&$hrId!==null){$updated=$database->execute('UPDATE employees SET status=\'TERMINATED\',deleted_at=NOW(),deleted_by=:user WHERE id=:id AND company_id=:company AND deleted_at IS NULL',['user'=>(int)$hrUser['id'],'id'=>$hrId,'company'=>$hrCompany]);if($updated!==1)Response::notFound();$auditLogger->log('DELETE','hrm',$hrId,null,['deleted'=>true],'employees',(int)$hrUser['id'],$hrCompany);Response::success(null,'Employee moved to the recycle bin.');}
}

if($hrResource==='attendance'){
    $auth->requirePermission('hr.attendance');
    if($hrMethod==='GET'){$pagination=Helpers::pagination();$params=['company'=>$hrCompany];$where='a.company_id=:company';foreach(['employee_id'=>'a.employee_id','status'=>'a.status']as$key=>$column){if(isset($_GET[$key])&&$_GET[$key]!==''){$where.=" AND $column=:$key";$params[$key]=$_GET[$key];}}if(isset($_GET['start_date'])&&$_GET['start_date']!==''){$where.=' AND a.attendance_date>=:start';$params['start']=$_GET['start_date'];}if(isset($_GET['end_date'])&&$_GET['end_date']!==''){$where.=' AND a.attendance_date<=:end';$params['end']=$_GET['end_date'];}$count=$database->fetchOne('SELECT COUNT(*) total FROM attendance a WHERE '.$where,$params);$rows=$database->fetchAll('SELECT a.*,e.employee_number,e.full_name employee_name,u.full_name recorded_by_name FROM attendance a INNER JOIN employees e ON e.id=a.employee_id INNER JOIN users u ON u.id=a.recorded_by WHERE '.$where.' ORDER BY a.attendance_date DESC,e.full_name LIMIT '.$pagination['per_page'].' OFFSET '.$pagination['offset'],$params);Response::success($rows,'Attendance loaded.',Helpers::paginationMeta((int)($count['total']??0),$pagination['page'],$pagination['per_page']));}
    if($hrMethod==='POST'){$data=karoor_hr_validate(karoor_hr_input(),['employee_id'=>'required|integer|min:1','attendance_date'=>'required|date:Y-m-d','check_in'=>'sometimes|nullable|string|max_length:30','check_out'=>'sometimes|nullable|string|max_length:30','status'=>'required|in:PRESENT,ABSENT,LATE,HALF_DAY,ON_LEAVE,HOLIDAY','notes'=>'sometimes|nullable|string|max_length:500']);try{$employee=karoor_hr_employee($database,(int)$data['employee_id'],$hrCompany);$checkIn=karoor_hr_datetime($data['check_in']??null,'check_in');$checkOut=karoor_hr_datetime($data['check_out']??null,'check_out');if($checkIn!==null&&substr($checkIn,0,10)!==$data['attendance_date']||$checkOut!==null&&substr($checkOut,0,10)!==$data['attendance_date'])throw new DomainException('Attendance times must fall on the attendance date.');if($checkIn!==null&&$checkOut!==null&&$checkOut<$checkIn)throw new DomainException('Check-out cannot be before check-in.');$old=$database->fetchOne('SELECT * FROM attendance WHERE employee_id=:employee AND attendance_date=:day',['employee'=>(int)$employee['id'],'day'=>$data['attendance_date']]);$database->execute('INSERT INTO attendance(company_id,employee_id,attendance_date,check_in,check_out,status,notes,recorded_by) VALUES(:company,:employee,:day,:check_in,:check_out,:status,:notes,:user) ON DUPLICATE KEY UPDATE check_in=VALUES(check_in),check_out=VALUES(check_out),status=VALUES(status),notes=VALUES(notes),recorded_by=VALUES(recorded_by)',['company'=>$hrCompany,'employee'=>(int)$employee['id'],'day'=>$data['attendance_date'],'check_in'=>$checkIn,'check_out'=>$checkOut,'status'=>$data['status'],'notes'=>$data['notes']??null,'user'=>(int)$hrUser['id']]);$record=$database->fetchOne('SELECT * FROM attendance WHERE employee_id=:employee AND attendance_date=:day',['employee'=>(int)$employee['id'],'day'=>$data['attendance_date']]);$auditLogger->log($old===null?'CREATE':'UPDATE','hrm',(int)$record['id'],$old,$record,'attendance',(int)$hrUser['id'],$hrCompany);}catch(DomainException$e){Response::error($e->getMessage(),[],422);}Response::success($record,'Attendance saved.',[],$old===null?201:200);}
}

if($hrResource==='leave-types'){
    $auth->requirePermission('hr.leave');
    if($hrMethod==='GET')Response::success($database->fetchAll('SELECT * FROM leave_types WHERE company_id=:company AND deleted_at IS NULL ORDER BY name',['company'=>$hrCompany]));
    if($hrMethod==='POST'){$data=karoor_hr_validate(karoor_hr_input(),['name'=>'required|string|max_length:100','days_per_year'=>'required|numeric|min:0','is_paid'=>'sometimes|boolean','requires_approval'=>'sometimes|boolean','is_active'=>'sometimes|boolean']);$id=$database->insert('leave_types',['company_id'=>$hrCompany,'name'=>$data['name'],'days_per_year'=>$data['days_per_year'],'is_paid'=>$data['is_paid']??1,'requires_approval'=>$data['requires_approval']??1,'is_active'=>$data['is_active']??1]);$auditLogger->log('CREATE','hrm',$id,null,$data,'leave_types',(int)$hrUser['id'],$hrCompany);Response::success(['id'=>$id],'Leave type created.',[],201);}
}

if($hrResource==='leave'){
    $auth->requirePermission('hr.leave');
    if($hrMethod==='GET'){$pagination=Helpers::pagination();$params=['company'=>$hrCompany];$where='lr.company_id=:company AND lr.deleted_at IS NULL';foreach(['employee_id'=>'lr.employee_id','status'=>'lr.status']as$key=>$column){if(isset($_GET[$key])&&$_GET[$key]!==''){$where.=" AND $column=:$key";$params[$key]=$_GET[$key];}}$count=$database->fetchOne('SELECT COUNT(*) total FROM leave_requests lr WHERE '.$where,$params);$rows=$database->fetchAll('SELECT lr.*,e.employee_number,e.full_name employee_name,lt.name leave_type,u.full_name reviewed_by_name FROM leave_requests lr INNER JOIN employees e ON e.id=lr.employee_id INNER JOIN leave_types lt ON lt.id=lr.leave_type_id LEFT JOIN users u ON u.id=lr.reviewed_by WHERE '.$where.' ORDER BY lr.start_date DESC,lr.id DESC LIMIT '.$pagination['per_page'].' OFFSET '.$pagination['offset'],$params);Response::success($rows,'Leave requests loaded.',Helpers::paginationMeta((int)($count['total']??0),$pagination['page'],$pagination['per_page']));}
    if($hrMethod==='POST'&&$hrId===null){$data=karoor_hr_validate(karoor_hr_input(),['employee_id'=>'required|integer|min:1','leave_type_id'=>'required|integer|min:1','start_date'=>'required|date:Y-m-d','end_date'=>'required|date:Y-m-d|after_or_equal:start_date','reason'=>'required|string|max_length:10000']);try{karoor_hr_employee($database,(int)$data['employee_id'],$hrCompany);$type=$database->fetchOne('SELECT * FROM leave_types WHERE id=:id AND company_id=:company AND is_active=1 AND deleted_at IS NULL',['id'=>(int)$data['leave_type_id'],'company'=>$hrCompany]);if($type===null)throw new DomainException('The selected leave type is unavailable.');$days=(new DateTimeImmutable($data['start_date']))->diff(new DateTimeImmutable($data['end_date']))->days+1;$id=$database->insert('leave_requests',['company_id'=>$hrCompany,'employee_id'=>(int)$data['employee_id'],'leave_type_id'=>(int)$data['leave_type_id'],'start_date'=>$data['start_date'],'end_date'=>$data['end_date'],'total_days'=>$days,'reason'=>$data['reason'],'status'=>(bool)$type['requires_approval']?'PENDING':'APPROVED','reviewed_by'=>(bool)$type['requires_approval']?null:(int)$hrUser['id'],'reviewed_at'=>(bool)$type['requires_approval']?null:date('Y-m-d H:i:s')]);$auditLogger->log('CREATE','hrm',$id,null,$data,'leave_requests',(int)$hrUser['id'],$hrCompany);}catch(DomainException$e){Response::error($e->getMessage(),[],422);}Response::success(['id'=>$id],'Leave request created.',[],201);}
    if($hrMethod==='POST'&&$hrId!==null&&in_array($hrAction,['approve','reject','cancel'],true)){$data=karoor_hr_validate(karoor_hr_input(),['review_notes'=>'sometimes|nullable|string|max_length:500']);$row=$database->fetchOne('SELECT * FROM leave_requests WHERE id=:id AND company_id=:company AND deleted_at IS NULL',['id'=>$hrId,'company'=>$hrCompany]);if($row===null)Response::notFound();if($row['status']!=='PENDING')Response::error('Only pending leave requests can be reviewed.',[],422);$status=match($hrAction){'approve'=>'APPROVED','reject'=>'REJECTED',default=>'CANCELLED'};$database->execute('UPDATE leave_requests SET status=:status,reviewed_by=:user,reviewed_at=NOW(),review_notes=:notes WHERE id=:id',['status'=>$status,'user'=>(int)$hrUser['id'],'notes'=>$data['review_notes']??null,'id'=>$hrId]);$auditLogger->log('UPDATE','hrm',$hrId,$row,['status'=>$status],'leave_requests',(int)$hrUser['id'],$hrCompany);Response::success(null,'Leave request updated.');}
}

if($hrResource==='payroll-components'){
    $auth->requirePermission('hr.payroll');
    if($hrMethod==='GET'){Response::success($database->fetchAll('SELECT pc.*,coa.code account_code,coa.name account_name FROM payroll_components pc LEFT JOIN chart_of_accounts coa ON coa.id=pc.chart_account_id WHERE pc.company_id=:company AND pc.deleted_at IS NULL ORDER BY pc.component_type,pc.name',['company'=>$hrCompany]));}
    if($hrMethod==='POST'){$data=karoor_hr_validate(karoor_hr_input(),['name'=>'required|string|max_length:120','component_type'=>'required|in:ALLOWANCE,OVERTIME,BONUS,DEDUCTION,TAX','calculation_type'=>'required|in:FIXED,PERCENTAGE','default_value'=>'required|numeric|min:0','percentage_base'=>'sometimes|nullable|in:BASIC,GROSS','chart_account_id'=>'sometimes|nullable|integer|min:1','is_taxable'=>'sometimes|boolean','is_active'=>'sometimes|boolean']);if($data['calculation_type']==='PERCENTAGE'&&($data['percentage_base']??null)===null)Response::validation(['percentage_base'=>['A percentage base is required.']]);$id=$database->insert('payroll_components',['company_id'=>$hrCompany,'name'=>$data['name'],'component_type'=>$data['component_type'],'calculation_type'=>$data['calculation_type'],'default_value'=>$data['default_value'],'percentage_base'=>$data['percentage_base']??null,'chart_account_id'=>$data['chart_account_id']??null,'is_taxable'=>$data['is_taxable']??0,'is_active'=>$data['is_active']??1]);$auditLogger->log('CREATE','hrm',$id,null,$data,'payroll_components',(int)$hrUser['id'],$hrCompany);Response::success(['id'=>$id],'Payroll component created.',[],201);}
}

function karoor_hr_payroll_items(Database $db, int $company, float $basic): array
{
    $items=[];$gross=$basic;
    foreach($db->fetchAll('SELECT * FROM payroll_components WHERE company_id=:company AND is_active=1 AND deleted_at IS NULL ORDER BY FIELD(component_type,\'ALLOWANCE\',\'OVERTIME\',\'BONUS\',\'DEDUCTION\',\'TAX\'),id',['company'=>$company])as$component){
        $base=$component['percentage_base']==='GROSS'?$gross:$basic;$amount=$component['calculation_type']==='PERCENTAGE'?$base*(float)$component['default_value']/100:(float)$component['default_value'];
        $rule=$db->fetchOne('SELECT * FROM payroll_component_rules WHERE payroll_component_id=:id AND is_active=1 AND lower_limit<=:base AND (upper_limit IS NULL OR upper_limit>:base) ORDER BY sort_order,lower_limit DESC LIMIT 1',['id'=>(int)$component['id'],'base'=>$base]);
        if($rule!==null)$amount=max(0,(float)$rule['fixed_amount']+$base*(float)$rule['rate']/100-(float)$rule['subtract_amount']);
        $amount=round($amount,4);if($amount<=0)continue;$items[]=['payroll_component_id'=>(int)$component['id'],'name'=>$component['name'],'component_type'=>$component['component_type'],'amount'=>$amount,'notes'=>null];if(in_array($component['component_type'],['ALLOWANCE','OVERTIME','BONUS'],true))$gross+=$amount;
    }
    return $items;
}

if($hrResource==='payroll'){
    $auth->requirePermission('hr.payroll');
    if($hrMethod==='GET'){
        if($hrId!==null){$row=$database->fetchOne('SELECT p.*,e.employee_number,e.full_name employee_name,e.position,d.name department_name FROM payroll p INNER JOIN employees e ON e.id=p.employee_id LEFT JOIN departments d ON d.id=e.department_id WHERE p.id=:id AND p.company_id=:company AND p.deleted_at IS NULL',['id'=>$hrId,'company'=>$hrCompany]);if($row===null)Response::notFound();$row['items']=$database->fetchAll('SELECT * FROM payroll_items WHERE payroll_id=:id ORDER BY id',['id'=>$hrId]);Response::success($row);}
        $pagination=Helpers::pagination();$params=['company'=>$hrCompany];$where='p.company_id=:company AND p.deleted_at IS NULL';foreach(['employee_id'=>'p.employee_id','status'=>'p.status','payroll_month'=>'p.payroll_month']as$key=>$column){if(isset($_GET[$key])&&$_GET[$key]!==''){$value=$_GET[$key];if($key==='payroll_month'&&preg_match('/^\d{4}-\d{2}$/',(string)$value))$value.='-01';$where.=" AND $column=:$key";$params[$key]=$value;}}$count=$database->fetchOne('SELECT COUNT(*) total FROM payroll p WHERE '.$where,$params);$rows=$database->fetchAll('SELECT p.*,e.employee_number,e.full_name employee_name,d.name department_name FROM payroll p INNER JOIN employees e ON e.id=p.employee_id LEFT JOIN departments d ON d.id=e.department_id WHERE '.$where.' ORDER BY p.payroll_month DESC,e.full_name LIMIT '.$pagination['per_page'].' OFFSET '.$pagination['offset'],$params);Response::success($rows,'Payroll loaded.',Helpers::paginationMeta((int)($count['total']??0),$pagination['page'],$pagination['per_page']));
    }
    if($hrMethod==='POST'&&$hrId===null){$data=karoor_hr_validate(karoor_hr_input(),['employee_id'=>'required|integer|min:1','payroll_month'=>'required|string|max_length:10','notes'=>'sometimes|nullable|string|max_length:500']);try{$month=preg_match('/^\d{4}-\d{2}$/',$data['payroll_month'])?$data['payroll_month'].'-01':$data['payroll_month'];$date=DateTimeImmutable::createFromFormat('!Y-m-d',$month);if($date===false||$date->format('Y-m-d')!==$month)throw new DomainException('A valid payroll month is required.');$id=$database->transaction(function(Database$db)use($data,$month,$hrCompany,$hrUser,$auditLogger):int{$employee=karoor_hr_employee($db,(int)$data['employee_id'],$hrCompany,true);$existing=$db->fetchOne('SELECT id FROM payroll WHERE employee_id=:employee AND payroll_month=:month FOR UPDATE',['employee'=>(int)$employee['id'],'month'=>$month]);if($existing!==null)throw new DomainException('Payroll has already been generated for this employee and month.');$items=karoor_hr_payroll_items($db,$hrCompany,(float)$employee['base_salary']);$totals=['ALLOWANCE'=>0.0,'OVERTIME'=>0.0,'BONUS'=>0.0,'DEDUCTION'=>0.0,'TAX'=>0.0];foreach($items as$item)$totals[$item['component_type']]+=(float)$item['amount'];$gross=round((float)$employee['base_salary']+$totals['ALLOWANCE']+$totals['OVERTIME']+$totals['BONUS'],4);$net=round($gross-$totals['DEDUCTION']-$totals['TAX'],4);if($net<0)throw new DomainException('Configured deductions exceed gross salary.');$id=$db->insert('payroll',['company_id'=>$hrCompany,'employee_id'=>(int)$employee['id'],'payroll_month'=>$month,'basic_salary'=>$employee['base_salary'],'total_allowances'=>$totals['ALLOWANCE'],'total_overtime'=>$totals['OVERTIME'],'total_bonuses'=>$totals['BONUS'],'total_deductions'=>$totals['DEDUCTION'],'total_tax'=>$totals['TAX'],'gross_salary'=>$gross,'net_salary'=>$net,'status'=>'DRAFT','notes'=>$data['notes']??null,'created_by'=>(int)$hrUser['id']]);foreach($items as$item)$db->insert('payroll_items',['payroll_id'=>$id]+$item);$auditLogger->log('CREATE','hrm',$id,null,['employee_id'=>(int)$employee['id'],'payroll_month'=>$month,'net_salary'=>$net],'payroll',(int)$hrUser['id'],$hrCompany);return$id;});}catch(DomainException$e){Response::error($e->getMessage(),[],422);}Response::success(['id'=>$id],'Payroll generated.',[],201);}
    if($hrMethod==='POST'&&$hrId!==null&&$hrAction==='approve'){try{$database->transaction(function(Database$db)use($hrId,$hrCompany,$hrUser,$auditLogger):void{$row=$db->fetchOne('SELECT * FROM payroll WHERE id=:id AND company_id=:company AND deleted_at IS NULL FOR UPDATE',['id'=>$hrId,'company'=>$hrCompany]);if($row===null)throw new DomainException('The payroll record was not found.');if($row['status']!=='DRAFT')throw new DomainException('Only draft payroll can be approved.');$db->execute('UPDATE payroll SET status=\'APPROVED\',approved_by=:user,approved_at=NOW() WHERE id=:id',['user'=>(int)$hrUser['id'],'id'=>$hrId]);$auditLogger->log('UPDATE','hrm',$hrId,$row,['status'=>'APPROVED'],'payroll',(int)$hrUser['id'],$hrCompany);});}catch(DomainException$e){Response::error($e->getMessage(),[],422);}Response::success(null,'Payroll approved.');}
    if($hrMethod==='POST'&&$hrId!==null&&$hrAction==='pay'){$data=karoor_hr_validate(karoor_hr_input(),['account_id'=>'required|integer|min:1','payment_method'=>'required|in:CASH,BANK,MOBILE_MONEY,OTHER']);try{$database->transaction(function(Database$db)use($data,$hrId,$hrCompany,$hrUser,$auditLogger):void{$payroll=$db->fetchOne('SELECT p.*,e.full_name employee_name FROM payroll p INNER JOIN employees e ON e.id=p.employee_id WHERE p.id=:id AND p.company_id=:company AND p.deleted_at IS NULL FOR UPDATE',['id'=>$hrId,'company'=>$hrCompany]);if($payroll===null)throw new DomainException('The payroll record was not found.');if($payroll['status']!=='APPROVED')throw new DomainException('Only approved payroll can be paid.');$account=$db->fetchOne('SELECT * FROM accounts WHERE id=:id AND company_id=:company AND is_active=1 AND deleted_at IS NULL',['id'=>(int)$data['account_id'],'company'=>$hrCompany]);$expense=$db->fetchOne('SELECT id FROM chart_of_accounts WHERE company_id=:company AND account_subtype=\'PAYROLL_EXPENSE\' AND is_active=1 AND deleted_at IS NULL ORDER BY is_system DESC,id LIMIT 1',['company'=>$hrCompany]);$payable=$db->fetchOne('SELECT id FROM chart_of_accounts WHERE company_id=:company AND account_subtype=\'PAYROLL_PAYABLE\' AND is_active=1 AND deleted_at IS NULL ORDER BY is_system DESC,id LIMIT 1',['company'=>$hrCompany]);if($account===null||$expense===null||$payable===null)throw new DomainException('The payroll expense, payable, or payment account is unavailable.');$journalNumber=Helpers::nextDocumentNumber($db,$hrCompany,$hrUser['branch_id']===null?null:(int)$hrUser['branch_id'],'JOURNAL');$journal=$db->insert('journal_entries',['company_id'=>$hrCompany,'branch_id'=>$hrUser['branch_id'],'entry_number'=>$journalNumber,'entry_date'=>date('Y-m-d'),'reference_type'=>'PAYROLL','reference_id'=>$hrId,'description'=>'Payroll: '.$payroll['employee_name'],'status'=>'POSTED','posted_at'=>date('Y-m-d H:i:s'),'posted_by'=>(int)$hrUser['id'],'created_by'=>(int)$hrUser['id']]);$lines=[[(int)$expense['id'],$payroll['gross_salary'],0],[(int)$account['chart_account_id'],0,$payroll['net_salary']]];$withheld=round((float)$payroll['gross_salary']-(float)$payroll['net_salary'],4);if($withheld>0)$lines[]=[(int)$payable['id'],0,$withheld];foreach($lines as[$coa,$debit,$credit])$db->insert('journal_entry_lines',['journal_entry_id'=>$journal,'chart_account_id'=>$coa,'customer_id'=>null,'supplier_id'=>null,'description'=>'Payroll payment','debit'=>$debit,'credit'=>$credit]);$number=Helpers::nextDocumentNumber($db,$hrCompany,$hrUser['branch_id']===null?null:(int)$hrUser['branch_id'],'PAYMENT');$payment=$db->insert('payments',['company_id'=>$hrCompany,'branch_id'=>$hrUser['branch_id'],'account_id'=>(int)$account['id'],'payment_number'=>$number,'direction'=>'OUT','payment_method'=>$data['payment_method'],'amount'=>$payroll['net_salary'],'payment_date'=>date('Y-m-d H:i:s'),'reference_number'=>null,'party_type'=>'EMPLOYEE','party_id'=>(int)$payroll['employee_id'],'description'=>'Payroll '.$payroll['payroll_month'],'journal_entry_id'=>$journal,'created_by'=>(int)$hrUser['id']]);$db->execute('UPDATE payroll SET status=\'PAID\',paid_at=NOW(),payment_id=:payment,journal_entry_id=:journal WHERE id=:id',['payment'=>$payment,'journal'=>$journal,'id'=>$hrId]);$auditLogger->log('PAYMENT','hrm',$hrId,$payroll,['status'=>'PAID','payment_id'=>$payment],'payroll',(int)$hrUser['id'],$hrCompany);});}catch(DomainException$e){Response::error($e->getMessage(),[],422);}Response::success(null,'Payroll paid and posted.');}
}

Response::notFound('The requested HRM endpoint was not found.');
