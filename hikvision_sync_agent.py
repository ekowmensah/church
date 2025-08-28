#!/usr/bin/env python3
"""
Hikvision Local Sync Agent (Python Example)
- Connects to Hikvision DS-K1T320MFWX via ISAPI/HTTP API on LAN
- Pulls fingerprint attendance logs and enrolled user list
- Pushes logs and user mapping data to cPanel-hosted API endpoints

Requirements:
- requests
- Python 3.x

Usage: python hikvision_sync_agent.py
"""
import requests
import json
import time
from datetime import datetime, timedelta

# CONFIGURATION
HIKVISION_HOST = '192.168.1.100'  # Change to device IP
HIKVISION_USER = 'admin'
HIKVISION_PASS = 'your_device_password'
CPANEL_API_BASE = 'https://yourchurchdomain.com/api/hikvision/'
API_KEY = 'replace_this_with_a_real_key'
POLL_INTERVAL_SECONDS = 60

# Helper: Basic Auth for Hikvision
from requests.auth import HTTPBasicAuth

def fetch_device_users():
    # Example: Fetch all users from device (adapt endpoint as needed)
    url = f'http://{HIKVISION_HOST}/ISAPI/AccessControl/UserInfo/Search?format=json'
    resp = requests.post(url, auth=HTTPBasicAuth(HIKVISION_USER, HIKVISION_PASS),
                        json={"UserInfoSearchCond": {"searchID": "1", "maxResults": 100}})
    resp.raise_for_status()
    users = resp.json().get('UserInfoSearch', {}).get('UserInfo', [])
    return users

def fetch_attendance_logs(since_time):
    # Example: Fetch fingerprint logs since last sync (adapt endpoint as needed)
    # Hikvision log API may vary; this is a placeholder
    url = f'http://{HIKVISION_HOST}/ISAPI/AccessControl/LogSearch?format=json'
    payload = {
        "LogSearchCond": {
            "searchID": "1",
            "searchResultPosition": 0,
            "maxResults": 50,
            "startTime": since_time.strftime('%Y-%m-%dT%H:%M:%SZ'),
            "endTime": datetime.utcnow().strftime('%Y-%m-%dT%H:%M:%SZ'),
            "Major": 5  # 5 = Fingerprint events (adjust as needed)
        }
    }
    resp = requests.post(url, auth=HTTPBasicAuth(HIKVISION_USER, HIKVISION_PASS), json=payload)
    resp.raise_for_status()
    logs = resp.json().get('LogList', {}).get('LogInfo', [])
    return logs

def push_logs_to_cpanel(logs):
    url = CPANEL_API_BASE + 'push-logs.php?key=' + API_KEY
    payload = {'logs': logs}
    resp = requests.post(url, json=payload, timeout=10)
    resp.raise_for_status()
    return resp.json()

def push_users_to_cpanel(users):
    url = CPANEL_API_BASE + 'push-users.php?key=' + API_KEY
    payload = {'users': users}
    resp = requests.post(url, json=payload, timeout=10)
    resp.raise_for_status()
    return resp.json()

def main():
    last_sync = datetime.utcnow() - timedelta(minutes=5)
    while True:
        print(f'[{datetime.now()}] Syncing with Hikvision device...')
        try:
            # 1. Fetch device users
            users = fetch_device_users()
            user_payload = []
            for u in users:
                user_payload.append({
                    'device_id': HIKVISION_HOST,
                    'hikvision_user_id': u.get('employeeNo'),
                    'member_id': None  # To be mapped by admin in cPanel UI
                })
            if user_payload:
                print(f'Pushing {len(user_payload)} users to cPanel...')
                push_users_to_cpanel(user_payload)

            # 2. Fetch attendance logs
            logs = fetch_attendance_logs(last_sync)
            log_payload = []
            for l in logs:
                log_payload.append({
                    'device_id': HIKVISION_HOST,
                    'hikvision_user_id': l.get('employeeNo'),
                    'timestamp': l.get('time'),
                    'event_type': 'fingerprint'
                })
            if log_payload:
                print(f'Pushing {len(log_payload)} logs to cPanel...')
                push_logs_to_cpanel(log_payload)

            last_sync = datetime.utcnow()
        except Exception as e:
            print('Error during sync:', e)
        time.sleep(POLL_INTERVAL_SECONDS)

if __name__ == '__main__':
    main()
