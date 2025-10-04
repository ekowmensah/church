# Birthday SMS System Setup Guide

## Overview
The Birthday SMS system automatically sends birthday greetings to church members on their birthdays. The system includes automated daily processing and manual management capabilities.

## Features
- **Automatic Daily SMS**: Sends birthday messages to all members with birthdays on the current date
- **Manual Triggers**: Admin interface to manually send birthday SMS
- **Test Functionality**: Send test birthday messages for verification
- **Comprehensive Logging**: All SMS activities are logged to database and files
- **Multi-Provider Support**: Works with existing SMS providers (Arkesel, Hubtel)

## Birthday Message Template
```
Happy Birthday, [Name]!

As you celebrate another year of God's faithfulness, we wish you all the good things you desire in life. May the Grace and Favour of God be multiplied unto you. Enjoy your special day, and stay blessed.

Freeman Methodist Church, Kwesimintsim.
```

## Files Created

### 1. Core Script
- **`scripts/birthday_sms_sender.php`** - Main birthday SMS processing script
- **`ajax_birthday_sms.php`** - AJAX endpoint for manual triggers
- **`views/birthday_sms_manager.php`** - Admin interface for birthday SMS management

### 2. Automation
- **`scripts/run_birthday_sms.bat`** - Windows batch file for task scheduling
- **`logs/birthday_sms_[date].log`** - Daily activity logs (auto-created)

## Setup Instructions

### 1. Database Setup
The system automatically adds a `type` column to the `sms_logs` table if it doesn't exist. No manual database changes are required.

### 2. SMS Configuration
Ensure your SMS configuration is properly set up in `config/sms_settings.json`:

```json
{
    "default_provider": "arkesel",
    "arkesel": {
        "url": "https://sms.arkesel.com/api/v2/sms/send",
        "api_key": "your_api_key",
        "sender": "FMC"
    },
    "hubtel": {
        "url": "https://api.hubtel.com/v1/messages/send",
        "api_key": "your_api_key",
        "api_secret": "your_api_secret",
        "sender": "FMC"
    }
}
```

### 3. Automated Daily Execution

#### Option A: Windows Task Scheduler (Recommended for Local/XAMPP)
1. Open Task Scheduler (`Win + R`, type `taskschd.msc`)
2. Click "Create Basic Task"
3. Name: "Church Birthday SMS"
4. Trigger: Daily at 8:00 AM
5. Action: Start a program
6. Program: `C:\xampp\htdocs\myfreemanchurchgit\church\scripts\run_birthday_sms.bat`
7. Start in: `C:\xampp\htdocs\myfreemanchurchgit\church\scripts`

#### Option B: Web-based Cron (For cPanel/Hosting)
Add this cron job to run daily at 8:00 AM:
```bash
0 8 * * * /usr/bin/php /path/to/church/scripts/birthday_sms_sender.php
```

#### Option C: Manual Web Trigger
You can also trigger the script via web browser:
```
https://yoursite.com/scripts/birthday_sms_sender.php?key=birthday_sms_2024_secret_key
```

**Important**: Change the secret key in `birthday_sms_sender.php` for security.

### 4. Admin Interface Access
1. Navigate to `views/birthday_sms_manager.php`
2. Requires login and SMS permissions
3. Features:
   - View today's birthday members
   - Send SMS to individual members
   - Send SMS to all birthday members
   - Test SMS functionality
   - View SMS activity logs

## Usage

### Automatic Operation
Once scheduled, the system will:
1. Run daily at the scheduled time
2. Check for members with birthdays on the current date
3. Send birthday SMS to each member found
4. Log all activities to database and files
5. Handle errors gracefully with detailed logging

### Manual Operation
Administrators can:
1. Access the Birthday SMS Manager interface
2. View members with birthdays today
3. Send individual or bulk birthday SMS
4. Test SMS functionality with custom phone numbers
5. Monitor recent SMS activity

## Monitoring and Logs

### Database Logs
All SMS activities are logged in the `sms_logs` table with:
- Member ID and phone number
- Message content
- Timestamp
- Status (success/fail)
- Provider used
- Full response details

### File Logs
Daily activity logs are created in `logs/birthday_sms_[date].log` containing:
- Processing start/end times
- Members found with birthdays
- SMS sending results
- Error details
- Summary statistics

### Filtering Logs
To view only birthday SMS logs:
```sql
SELECT * FROM sms_logs WHERE type = 'birthday' ORDER BY sent_at DESC;
```

## Security Considerations

1. **Web Access Protection**: The script requires a secret key for web access
2. **Permission Checks**: Admin interface requires proper user permissions
3. **Input Validation**: All inputs are validated and sanitized
4. **Error Handling**: Comprehensive error handling prevents system crashes

## Troubleshooting

### Common Issues

1. **No SMS Sent**
   - Check SMS configuration in `config/sms_settings.json`
   - Verify API credentials are correct
   - Check logs for error details

2. **Members Not Found**
   - Verify members have valid DOB (not 0000-00-00 or empty)
   - Ensure phone numbers are not empty
   - Check date format in database

3. **Permission Errors**
   - Ensure user has `send_sms` permission
   - Check if user is logged in properly

4. **Scheduling Issues**
   - Verify PHP path in batch file
   - Check Task Scheduler logs
   - Ensure script has proper file permissions

### Debug Mode
To enable debug output, run the script manually:
```bash
php scripts/birthday_sms_sender.php
```

## Testing

### Test Individual SMS
1. Go to Birthday SMS Manager
2. Use the "Test Birthday SMS" section
3. Enter a phone number and name
4. Click "Send Test SMS"

### Test with Actual Birthdays
1. Temporarily update a member's DOB to today's date
2. Run the script manually or use the admin interface
3. Verify SMS is sent and logged
4. Restore the original DOB

## Maintenance

### Regular Tasks
1. **Monitor Logs**: Check daily logs for errors
2. **Verify SMS Credits**: Ensure SMS provider has sufficient credits
3. **Update Phone Numbers**: Keep member phone numbers current
4. **Test Periodically**: Send test SMS monthly to verify functionality

### Updates
When updating the system:
1. Backup current files
2. Test changes in development environment
3. Update production files
4. Verify scheduled tasks still work
5. Monitor logs for any issues

## Support

For issues or questions:
1. Check the log files first
2. Verify SMS configuration
3. Test with manual triggers
4. Contact system administrator if problems persist

---

**Note**: This system respects member privacy and only sends birthday messages to members with valid phone numbers and birthdates in the system.
