# Hikvision Attendance Integration Setup Guide

## Overview
This guide covers the complete setup and usage of the Hikvision DS-K1T320MFWX face recognition terminal integration with your church management system.

## Components

### 1. Sync Agent (`hikvision_sync_agent.php`)
- **Purpose**: Polls the Hikvision device for attendance logs and syncs them to the cloud
- **Location**: `c:\xampp\htdocs\myfreemanchurchgit\church\hikvision_sync_agent.php`
- **Configuration**: 
  - Device IP: `192.168.5.201`
  - Username: `admin`
  - Password: `223344AD`
  - API Key: `0c6c5401ab9f1af81c7cbadee3279663a918a16407fbc84a0d4bd189789d9f49`

### 2. Cloud API Endpoint (`api/hikvision/push-logs.php`)
- **Purpose**: Receives attendance logs from sync agent and processes them
- **URL**: `https://myfreeman.mensweb.xyz/api/hikvision/push-logs.php`
- **Authentication**: API key required

### 3. User Mapping Interface
- **URL**: `https://myfreeman.mensweb.xyz/views/member_hikvision_mapping.php`
- **Purpose**: Map Hikvision device users to church members

## Setup Instructions

### Step 1: Automatic Sync Setup
1. **Run as Administrator**: Right-click Command Prompt → "Run as administrator"
2. **Execute**: `setup_hikvision_scheduler.bat`
3. **Verify**: Task will run every 10 minutes automatically

### Step 2: Manual Sync (Testing)
- **Double-click**: `hikvision_sync_manual.bat` to test sync manually
- **Check output**: Should show "No new logs to sync" if no attendance events

### Step 3: Member Mapping
1. **Access**: Go to Hikvision → User Mapping in the church system
2. **Map Users**: For each unmapped device user, select corresponding church member
3. **Save**: Click "Map" to establish the connection

## Usage Workflow

### Daily Operations
1. **Members use device**: Face recognition or card access on Hikvision terminal
2. **Automatic sync**: Sync agent runs every 10 minutes
3. **Attendance records**: Appear automatically in church system reports

### Monitoring
- **Check logs**: Review sync agent output for errors
- **Verify mapping**: Ensure all active users are mapped to members
- **Session creation**: System auto-creates attendance sessions by date/service type

## Troubleshooting

### Common Issues

**1. "No new logs to sync"**
- Normal when no attendance events occurred
- Device is responding correctly

**2. "No member mapping" error**
- Unmapped device user attempted attendance
- Go to User Mapping interface to map the user

**3. "Failed to connect to device"**
- Check device IP address and network connectivity
- Verify device credentials (admin/223344AD)

**4. API authentication errors**
- Verify API key matches in both sync agent and push-logs.php
- Check cloud server accessibility

### Manual Commands

```batch
# Test sync manually
php hikvision_sync_agent.php

# Check scheduled task status
schtasks /query /tn "Hikvision Attendance Sync"

# Delete scheduled task
schtasks /delete /tn "Hikvision Attendance Sync" /f
```

## Data Flow

1. **Device Event**: Member uses face recognition/card
2. **Raw Log**: Stored in `hikvision_raw_logs` table
3. **Member Lookup**: Maps device user to church member
4. **Session Creation**: Auto-creates attendance session if needed
5. **Attendance Record**: Creates entry in `attendance_records` table
6. **Reports**: Data appears in church attendance reports

## Session Auto-Creation Rules

- **Sunday**: "Sunday Service"
- **Wednesday**: "Midweek Service" 
- **Friday**: "Friday Service"
- **Other days**: "General Service"

## Security Notes

- API key provides secure communication between local sync agent and cloud
- Device credentials are stored locally only
- All attendance data is encrypted in transit

## Support

For technical issues:
1. Check this guide first
2. Review sync agent output logs
3. Verify device connectivity and mapping
4. Contact system administrator if issues persist
