# PPMP System - Consolidated Items Management

A PHP PDO-based Project Procurement Management Plan (PPMP) system with consolidated items management.

## Features

- ✅ **Consolidated Items Management**: Centralized item database
- ✅ **User-Friendly PPMP Creation**: Select items from dropdown, auto-populate details
- ✅ **Read-Only Item Details**: Users cannot edit item information in PPMP
- ✅ **Real-time Calculations**: Automatic quarterly and total cost calculations
- ✅ **Responsive Design**: Modern UI with Bootstrap and Argon Dashboard
- ✅ **Secure API**: PDO prepared statements prevent SQL injection

## Setup Instructions

### 1. Database Setup
```sql
-- Create database
CREATE DATABASE ppmp_system;
USE ppmp_system;

-- Run the schema
SOURCE database_schema.sql;
```

### 2. Environment Configuration
Update your `.env` file:
```
API_BASE_URL=http://localhost/SystemsMISPYO/PPMP/apiPPMP
DB_HOST=localhost
DB_NAME=ppmp_system
DB_USER=root
DB_PASS=your_password
```

### 3. Install Dependencies
```bash
composer install
```

### 4. Access the System
- **PPMP Page**: `http://localhost/SystemsMISPYO/PPMP/system/ppmp.php`
- **Items Management**: `http://localhost/SystemsMISPYO/PPMP/system/manage_ppmp_items.php`

## System Architecture

### Database Tables
- `tbl_ppmp_bac_items` - Main items table
- `tbl_ppmp_documents` - PPMP header information
- `tbl_ppmp_entries` - PPMP line items
- `tbl_users` - User management

### API Endpoints
- `GET /apiPPMP/get_items.php` - Fetch all items
- `POST /apiPPMP/api_save_ppmp_item.php` - Save new item

### Key Files
- `system/ppmp.php` - Main PPMP interface
- `system/manage_ppmp_items.php` - Items management
- `apiPPMP/get_items.php` - Items API
- `config.php` - Database configuration

## Usage

### For Administrators
1. Go to **Management Tools** → **PPMP Items Management**
2. Click **"➕ Add Item"** to add new items
3. Items will be available in the PPMP system

### For Users
1. Go to **PPMP** page
2. Click **"Add Row"** to add items
3. Select item from dropdown
4. Item details auto-populate (read-only)
5. Enter quantities for each month
6. System calculates totals automatically

## Security Features

- PDO prepared statements
- Input validation
- CORS headers
- Session management
- Password hashing

## Sample Data

The system includes sample items:
- Office supplies (Ballpen, Bond Paper, etc.)
- Equipment (Printer Ink, USB Drives, etc.)
- With realistic pricing

## Troubleshooting

### Common Issues

1. **API_BASE_URL not defined**
   - Ensure `.env` file has correct `API_BASE_URL`
   - Check `api_config.js.php` is included

2. **Database connection error**
   - Verify `.env` database credentials
   - Ensure database exists and schema is loaded

3. **Items not loading**
   - Check browser console for errors
   - Verify `get_items.php` returns valid JSON

### Debug Mode
Set `error_reporting(E_ALL)` in PHP files to see detailed errors.

## Support

For issues or questions, check:
- Browser developer console
- PHP error logs
- Database connection status