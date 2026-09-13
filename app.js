const express = require('express');
const path = require('path');
const helmet = require('helmet');
const session = require('express-session');
const MySQLStore = require('express-mysql-session')(session);
require('dotenv').config();

const { pool } = require('./server/config/database');
const authRoutes = require('./server/routes/auth');
const apiProducts = require('./server/routes/inventory');
const apiUsers = require('./server/routes/users');
const { errorHandler } = require('./server/middleware/errorHandler');

const app = express();
const PORT = process.env.PORT || 3000;

// Security
app.use(helmet());
app.use(express.json());
app.use(express.urlencoded({ extended: false }));

// Session store
const sessionStore = new MySQLStore({}, pool.promise ? pool.promise() : pool);

app.use(session({
  key: 'erp_session',
  secret: process.env.SESSION_SECRET || 'change_me',
  store: sessionStore,
  resave: false,
  saveUninitialized: false,
  cookie: {
    httpOnly: true,
    sameSite: 'lax',
    secure: process.env.NODE_ENV === 'production',
    maxAge: 1000 * 60 * 60 * 8 // 8 hours
  }
}));

// Static files
app.use(express.static(path.join(__dirname, 'public')));

// API routes
app.use('/api/auth', authRoutes);
app.use('/api/products', apiProducts);
app.use('/api/users', apiUsers);

// Health
app.get('/api/dashboard', (req, res) => {
  res.json({ ok: true, message: 'ERP API running' });
});

// Error handler
app.use(errorHandler);

app.listen(PORT, () => {
  console.log(`ERP app listening on port ${PORT}`);
});
