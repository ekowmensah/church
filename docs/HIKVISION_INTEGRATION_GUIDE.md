# HikVision DS-K1T320MFWX Integration Guide

## Overview
This guide covers the complete integration of HikVision DS-K1T320MFWX face recognition terminals with your church attendance management system.

## System Architecture

### Database Tables
- **`hikvision_devices`**: Device management and configuration
- **`hikvision_raw_logs`**: Raw access logs from devices
- **`member_hikvision_data`**: Member enrollment mapping
- **`attendance_records`**: Updated with HikVision support

### Key Components
- **`HikVisionService.php`**: Core service for device communication
- **`hikvision_devices.php`**: Device management interface
- **`hikvision_enrollment.php`**: Face enrollment interface
- **`ajax_hikvision_sync.php`**: AJAX endpoints for real-time operations

## Implementation Steps

### 1. Database Setup
Execute the migration file to create required tables:
```sql
-- Run this in your database
SOURCE migrations/20250817_create_hikvision_devices_table.sql;
```

### 2. Device Configuration
1. Access **System > HikVision Devices** in your admin panel
2. Add your DS-K1T320MFWX device with:
   - Device name and location
   - IP address and port (default: 80)
   - Admin username/password
3. Test connection to verify setup

### 3. Member Enrollment
1. Navigate to device management
2. Click "Manage Users" for your device
3. Enroll members by:
   - Adding them to the device user list
   - Uploading face photos for recognition

### 4. Attendance Sync
- **Manual Sync**: Use the sync button in device management
- **Automatic Sync**: Set up cron job (see below)

## Device API Integration

### Supported Operations
- **Device Info**: Get firmware version, status
- **User Management**: Add/remove users
- **Face Enrollment**: Upload face templates
- **Access Logs**: Retrieve attendance records
- **Real-time Events**: Process access events

### Authentication
Uses HTTP Digest authentication with device admin credentials.

## Attendance Workflow

1. **Member Recognition**: Device recognizes enrolled face
2. **Log Creation**: Access event stored in `hikvision_raw_logs`
3. **Processing**: Service maps device user to church member
4. **Attendance Record**: Creates entry in `attendance_records`
5. **Session Linking**: Optionally links to specific church service

## Configuration Options

### Device Settings
```php
// In hikvision_devices table
$device = [
    'ip_address' => '192.168.1.100',
    'port' => 80,
    'username' => 'admin',
    'password' => 'your_password',
    'max_users' => 3000
];
```

### Sync Settings
- **Manual**: On-demand via web interface
- **Scheduled**: Cron job every 15 minutes
- **Real-time**: Webhook integration (advanced)

## Automated Sync Setup

### Cron Job Configuration
Add to your server's crontab:
```bash
# Sync every 15 minutes
*/15 * * * * /usr/bin/php /path/to/church/cron/hikvision_sync.php
```

### Create Sync Script
```php
<?php
// cron/hikvision_sync.php
require_once '../config/database.php';
require_once '../helpers/HikVisionService.php';

$devices = HikVisionService::getActiveDevices($conn);
foreach ($devices as $device) {
    try {
        $service = new HikVisionService($conn, $device['id']);
        $result = $service->syncAttendance();
        echo "Device {$device['device_name']}: {$result['synced_count']} records\n";
    } catch (Exception $e) {
        echo "Error with device {$device['device_name']}: {$e->getMessage()}\n";
    }
}
?>
```

## Security Considerations

### Network Security
- Use HTTPS for web interface
- Secure device network (VLAN recommended)
- Change default device passwords
- Regular firmware updates

### Data Protection
- Face templates encrypted in device
- Access logs contain timestamps only
- Member data properly anonymized

## Troubleshooting

### Common Issues

**Connection Failed**
- Check IP address and port
- Verify network connectivity
- Confirm device credentials

**Face Recognition Poor**
- Ensure good lighting
- Use high-quality photos
- Remove glasses/hats
- Multiple angles may help

**Sync Issues**
- Check device time synchronization
- Verify database permissions
- Review error logs

### Debug Mode
Enable debug logging in `HikVisionService.php`:
```php
// Add to constructor
private $debug = true;
```

## Performance Optimization

### Database Indexing
Key indexes already created:
- `idx_device_time` on raw logs
- `idx_processed` for sync status
- `idx_user_time` for member lookup

### Sync Optimization
- Process only new logs (use timestamps)
- Batch operations for large datasets
- Clean up old raw logs periodically

## Integration with Existing Features

### Attendance Reports
HikVision attendance automatically appears in:
- Daily attendance reports
- Member attendance history
- Service-specific reports

### Member Management
- Face enrollment status shown in member profiles
- Bulk enrollment tools available
- Enrollment history tracking

## API Extensions

### Custom Endpoints
Add custom functionality by extending `HikVisionService`:
```php
public function customFunction() {
    return $this->makeRequest('/ISAPI/Custom/Endpoint', 'GET');
}
```

### Webhook Integration
For real-time processing, implement webhook receiver:
```php
// webhook_receiver.php
$json = file_get_contents('php://input');
$data = json_decode($json, true);
// Process real-time events
```

## Maintenance

### Regular Tasks
- **Weekly**: Review sync logs
- **Monthly**: Clean old raw logs
- **Quarterly**: Update device firmware
- **Annually**: Review user enrollments

### Monitoring
- Device connection status
- Sync success rates
- Recognition accuracy
- System performance

## Support and Updates

### Documentation
- API reference: HikVision ISAPI documentation
- Device manual: DS-K1T320MFWX user guide
- System logs: `/logs/hikvision_*.log`

### Version Compatibility
- Tested with firmware v1.4.x
- Compatible with ISAPI v2.0+
- Requires PHP 7.4+ with cURL

---

**Implementation Status**: ✅ Complete
**Last Updated**: August 17, 2025
**Version**: 1.0.0
