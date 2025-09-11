# Hubtel Shortcode Payment Integration Setup Guide

## Overview
This guide explains how to set up and configure Hubtel shortcode payment integration so that members can make payments via shortcode dialing and have them automatically recorded in your church management system.

## System Components

### 1. Webhook Endpoint
- **File**: `api/hubtel_shortcode_webhook.php`
- **Purpose**: Receives payment notifications from Hubtel
- **URL**: `https://portal.myfreeman.org/api/hubtel_shortcode_webhook.php`

### 2. Database Table
- **Table**: `unmatched_payments`
- **Purpose**: Stores payments that couldn't be automatically matched to members
- **Migration**: `database/migrations/20250906_create_unmatched_payments_table.sql`

### 3. Admin Interface
- **File**: `views/unmatched_payments_list.php`
- **Purpose**: Allows admins to manually assign unmatched payments to members

## Setup Instructions

### Step 1: Database Setup
Run the migration to create the unmatched payments table:

```sql
-- Execute this SQL in your database
SOURCE database/migrations/20250906_create_unmatched_payments_table.sql;
```

### Step 2: Hubtel Configuration
1. **Contact Hubtel Support** to set up USSD Programmable Services
2. **Configure Service Interaction URL**: `https://portal.myfreeman.org/api/hubtel_ussd_service.php`
3. **Configure Service Fulfillment URL**: `https://portal.myfreeman.org/api/hubtel_shortcode_webhook.php`
4. **Request USSD shortcode** assignment (e.g., *713#)
5. **Test both endpoints** with Hubtel's sandbox environment

### Step 3: Test the Integration
1. **Test USSD Flow**: Dial your assigned shortcode (e.g., *713*9597#)
2. **Check USSD logs**: `logs/ussd_service_debug.log`
3. **Complete test payment**: Follow USSD prompts to make a payment
4. **Check fulfillment logs**: `logs/shortcode_webhook_debug.log`
5. **Verify payment recording**: Check if payment appears in system or unmatched payments
6. **Test member assignment**: Use admin interface for unmatched payments

## How It Works

### Complete Payment Flow
```
1. User dials USSD shortcode (e.g., *713*9597#)
2. Hubtel sends request to Service Interaction URL
3. System responds with donation menu
4. User selects donation type and amount
5. System responds with AddToCart
6. Hubtel processes payment
7. Hubtel sends fulfillment to Service Fulfillment URL
8. System records payment and sends SMS confirmation
```

### API Endpoints

#### Service Interaction URL
- **URL**: `/api/hubtel_ussd_service.php`
- **Purpose**: Handles USSD session interactions
- **Request Format**: Hubtel Programmable Services format
- **Response**: USSD menu responses

#### Service Fulfillment URL
- **URL**: `/api/hubtel_shortcode_webhook.php`
- **Purpose**: Processes completed payments
- **Request Format**: Hubtel Service Fulfillment format
- **Response**: Success/error status

### Expected Request Formats

#### USSD Service Interaction Request
```json
{
  "Type": "Initiation",
  "Mobile": "233200585542",
  "SessionId": "3c796dac28174f739de4262d08409c51",
  "ServiceCode": "713",
  "Message": "*713*9597#",
  "Operator": "vodafone",
  "Sequence": 1,
  "ClientState": "",
  "Platform": "USSD"
}
```

#### Service Fulfillment Request
```json
{
  "SessionId": "3c796dac28174f739de4262d08409c51",
  "OrderId": "ac3307bcca7445618071e6b0e41b50b5",
  "OrderInfo": {
    "CustomerMobileNumber": "233200585542",
    "Status": "Paid",
    "OrderDate": "2023-11-06T15:16:50.3581338+00:00",
    "Currency": "GHS",
    "Subtotal": 151.50,
    "Items": [
      {
        "ItemId": "5b8945940e1247489e34e756d8fc2dbb",
        "Name": "General Offering",
        "Quantity": 1,
        "UnitPrice": 150.5
      }
    ],
    "Payment": {
      "PaymentType": "mobilemoney",
      "AmountPaid": 151.50,
      "AmountAfterCharges": 150.5,
      "PaymentDate": "2023-11-06T15:16:50.3581338+00:00",
      "IsSuccessful": true
    }
  }
}
```

### Legacy Payment Flow (Deprecated)
**Note**: The original simple webhook approach has been replaced with the proper Hubtel Programmable Services API implementation above.
```
Member dials shortcode → Hubtel processes payment → Webhook notification → Your system
```
{{ ... }}
  }
}
### Member Identification
The system tries to identify members in this order:
1. **Phone Number Match**: Match payment phone with member records
2. **CRN Extraction**: Extract CRN from payment reference/description
3. **Manual Assignment**: Store as unmatched for admin assignment

### Automatic Processing
When a member is identified:
- Payment is recorded in the `payments` table
- SMS confirmation is sent to the member
- Payment appears in member's payment history

### Manual Assignment
When a member cannot be identified:
- Payment is stored in `unmatched_payments` table
- Admin can search and assign to correct member
- Once assigned, payment moves to regular payments table

## Configuration Options

### Phone Number Normalization
The system automatically normalizes phone numbers:
- Removes +233 country code
- Adds leading 0 if missing
- Ensures 10-digit format

### CRN Pattern Recognition
Recognizes CRN patterns like:
- `FMC-K0101-KM`
- `FMCK0101KM`
- `FMC K0101 KM`

### Default Settings
- **Payment Type**: General Offering (ID: 1)
- **Payment Mode**: Mobile Money
- **Recorded By**: "Shortcode Payment"

## Admin Management

### Access Unmatched Payments
Navigate to: **Payments → Unmatched Shortcode Payments**

### Required Permission
Users need `manage_payments` permission to access the interface.

### Assignment Process
1. View unmatched payments list
2. Click "Assign" button
3. Search for member by name or CRN
4. Select correct member
5. Confirm assignment

## Monitoring and Logs

### Log Files
- `logs/shortcode_webhook_debug.log` - Detailed processing logs
- `logs/shortcode_webhook_raw.log` - Raw webhook data
- `logs/sms_debug_*.log` - SMS confirmation logs

### Key Metrics to Monitor
- Number of successful auto-matches
- Number of unmatched payments requiring manual assignment
- SMS confirmation success rate
- Webhook response times

## Troubleshooting

### Common Issues

**1. Payments Not Appearing**
- Check webhook URL is correctly configured in Hubtel
- Verify webhook logs for errors
- Ensure database connection is working

**2. Members Not Being Matched**
- Verify member phone numbers are up to date
- Check CRN format in payment references
- Review phone number normalization logic

**3. SMS Confirmations Not Sending**
- Check SMS configuration in `.env` file
- Verify SMS logs for error messages
- Ensure member has valid phone number

### Debug Steps
1. Check raw webhook data in logs
2. Verify member data in database
3. Test member search functionality
4. Review payment creation process

## Security Considerations

### Webhook Security
- Webhook endpoint validates required fields
- Logs all incoming requests for audit
- Handles malformed data gracefully

### Data Protection
- Sensitive payment data is logged securely
- Member information is protected
- Admin actions are tracked

## Best Practices

### Member Data Maintenance
- Keep member phone numbers updated
- Ensure CRN format consistency
- Regular data cleanup of inactive members

### Admin Training
- Train staff on unmatched payment assignment
- Establish procedures for handling disputes
- Regular review of unmatched payments

### Monitoring
- Set up alerts for high numbers of unmatched payments
- Regular review of webhook logs
- Monitor SMS delivery rates

## Support and Maintenance

### Regular Tasks
- Review unmatched payments weekly
- Clean up old assigned payments
- Monitor webhook performance
- Update member contact information

### When to Contact Hubtel
- Webhook format changes
- Payment processing issues
- New shortcode requirements
- Integration updates

## Testing Checklist

- [ ] Database migration completed
- [ ] Webhook URL configured in Hubtel
- [ ] Test payment processed successfully
- [ ] Member auto-matching working
- [ ] SMS confirmations sending
- [ ] Admin interface accessible
- [ ] Member search functioning
- [ ] Payment assignment working
- [ ] Logs being generated properly

## Next Steps

1. **Deploy to Production**: Upload files to portal.myfreeman.org
2. **Run Database Migration**: Execute the SQL migration
3. **Configure Hubtel**: Set up webhook URL with Hubtel
4. **Test Integration**: Make test payments
5. **Train Admins**: Show staff how to manage unmatched payments
6. **Monitor Performance**: Watch logs and metrics

For technical support, contact your development team or refer to the system documentation.
