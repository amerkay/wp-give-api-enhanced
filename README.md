# GiveWP Enhanced API

A WordPress plugin that extends GiveWP with 5x enhanced REST API endpoints providing complete donation, donor, subscription, campaign, and form data including all custom fields and Gift Aid information.

**Problem this fixes**: The built-in API is too basic to build a proper n8n or Zapier integration. There is currently (as of Nov 2025) no way to load Gift Aid data or Custom Field (meta data) for donations or donors. That's why I built this plugin.

## Features

- **Complete Data Access** - Full custom field and Gift Aid data in every response
- **Nested Relationships** - Less API calls needed as donation responses include donor, form, campaign, and subscription data
- **Secure Authentication** - Uses GiveWP's native API authentication (GiveWP → Tools → API)
- **Zero Configuration** - Works immediately after activation
- **5 Enhanced Endpoints** - Donation, Donor, Subscription, Campaign, and Form resources

## Requirements

- WordPress v6.x (tested with version 6.8.3)
- PHP 8 (tested on version 8.3)
- GiveWP plugin v4 (tested with version 4.13.1)

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate through the WordPress Plugins menu
3. Generate API credentials in GiveWP → Tools → API

## API Authentication

### Generate Credentials

1. Navigate to **GiveWP → Tools → API**
2. Click **Generate API Keys**
3. Select a user and click **Generate**
4. Copy your **Public Key** and **Token**

### Standard API Requests

Append credentials as query parameters:

```
https://your-site.com/wp-json/give-api-enhanced/v1/donation/123?key=PUBLIC_KEY&token=TOKEN
```

## Endpoints

All endpoints require `key` and `token` query parameters.

### Get Donation

```
GET /wp-json/give-api-enhanced/v1/donation/{id}
```

Returns complete donation data with nested donor, form, campaign, and subscription information.

**Example Response:**
```json
{
  "donation": {
    "id": 154,
    "amount": {
      "amount": "10558",
      "currency": "GBP",
      "formatted": "105.58"
    },
    "firstName": "John",
    "lastName": "Doe",
    "email": "john@example.com",
    "status": "publish",
    "createdAt": "2025-11-21 03:20:55",
    "donor": {
      "id": 1,
      "totalAmountDonated": {
        "amount": "57011",
        "currency": "GBP",
        "formatted": "570.11"
      },
      "meta": {
        "_give_gift_aid_card_address": "1 Test Rd",
        "_give_gift_aid_card_zip": "L11 D11",
        "_give_gift_aid_opt_in_date": "2025-11-21 03:20:55"
      }
    },
    "form": {
      "id": 10,
      "title": "Donation Form",
      "levels": [...]
    },
    "campaign": {
      "id": 1,
      "title": "Fundraising Campaign",
      "goal": 2000,
      "goal_stats": {
        "actual": 555,
        "percentage": 27.75
      }
    },
    "subscription": {
      "id": 6,
      "status": "active",
      "period": "month",
      "frequency": 1
    }
  }
}
```

### Get Donor

```
GET /wp-json/give-api-enhanced/v1/donor/{id}
```

Returns donor details with all custom meta fields including Gift Aid data.

**Example Response:**
```json
{
  "donor": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com",
    "totalAmountDonated": {
      "amount": "57011",
      "currency": "GBP",
      "formatted": "570.11"
    },
    "totalNumberOfDonations": 8,
    "meta": {
      "_give_gift_aid_card_address": "1 Test Rd",
      "_give_gift_aid_card_city": "London",
      "_give_gift_aid_card_zip": "L11 D11",
      "_give_gift_aid_opt_in_date": "2025-11-21 03:20:55",
      "additional_email": "john.alternate@example.com"
    }
  }
}
```

### Get Subscription

```
GET /wp-json/give-api-enhanced/v1/subscription/{id}
```

Returns recurring subscription details.

**Example Response:**
```json
{
  "subscription": {
    "id": 6,
    "status": "active",
    "period": "month",
    "frequency": 1,
    "installments": 0,
    "amount": {
      "amount": "10558",
      "currency": "GBP",
      "formatted": "105.58"
    },
    "renewsAt": "2025-12-21 03:20:55"
  }
}
```

### Get Campaign

```
GET /wp-json/give-api-enhanced/v1/campaign/{id}
```

Returns campaign information with goal statistics.

**Example Response:**
```json
{
  "campaign": {
    "id": 1,
    "title": "Fundraising Campaign",
    "goal": 2000,
    "goalType": "amount",
    "status": "active",
    "goal_stats": {
      "actual": 555,
      "actualFormatted": "&pound;555.00",
      "percentage": 27.75,
      "goal": 2000,
      "goalFormatted": "&pound;2,000.00"
    }
  }
}
```

### Get Form

```
GET /wp-json/give-api-enhanced/v1/form/{id}
```

Returns donation form configuration and statistics.

**Example Response:**
```json
{
  "form": {
    "id": 10,
    "title": "Donation Form",
    "status": "publish",
    "totalNumberOfDonations": 8,
    "totalAmountDonated": {
      "amount": "57011",
      "currency": "GBP",
      "formatted": "570.11"
    },
    "levels": [
      {
        "id": 0,
        "amount": {
          "value": "10.00",
          "currency": "GBP"
        },
        "label": "Supporter"
      }
    ]
  }
}
```

## Usage Examples

### n8n Workflow

1. Add **HTTP Request** node
2. Set **Authentication** to **Generic Credential Type** → **Custom Auth**
3. Create credential with:
   ```json
   {
     "qs": { 
       "key": "YOUR_PUBLIC_KEY",
       "token": "YOUR_TOKEN"
     }
   }
   ```
4. Set **Allowed Domains:** `your-site.com, www.your-site.com`
5. Set **Method:** GET
6. Set **URL:** `https://your-site.com/wp-json/give-api-enhanced/v1/donation/{{$json["donation_id"]}}`

### cURL

```bash
curl "https://your-site.com/wp-json/give-api-enhanced/v1/donation/123?key=YOUR_PUBLIC_KEY&token=YOUR_TOKEN"
```


## Error Responses

### 401 - Missing Credentials
```json
{
  "code": "missing_credentials",
  "message": "API key and token are required.",
  "data": { "status": 401 }
}
```

### 403 - Invalid Credentials
```json
{
  "code": "invalid_credentials",
  "message": "Invalid API key or token.",
  "data": { "status": 403 }
}
```

### 404 - Resource Not Found
```json
{
  "code": "donation_not_found",
  "message": "Donation not found.",
  "data": { "status": 404 }
}
```

### 503 - GiveWP Not Active
```json
{
  "code": "givewp_not_active",
  "message": "GiveWP plugin is not active.",
  "data": { "status": 503 }
}
```

## Security

- Uses GiveWP's native MD5 token validation (`md5(secret + public_key)`)
- All requests must include valid credentials
- API keys are managed through GiveWP's admin interface
- Keys can be revoked instantly from GiveWP → Tools → API

## Troubleshooting

**"Invalid API key or token" error:**
- Verify credentials in GiveWP → Tools → API
- Regenerate keys if necessary
- Check for extra spaces in key/token values

**Empty or missing data:**
- Ensure the resource ID exists
- Check that GiveWP is active and up to date
- Verify custom fields are properly saved in GiveWP

**404 errors:**
- Confirm WordPress permalinks are enabled
- Re-save permalinks: Settings → Permalinks → Save Changes
- Verify the endpoint URL is correct

## License

GPLv2. See LICENSE.md.

## Author

[Amer Kawar](https://wildamer.com)

## Support

For issues and feature requests, visit the [GitHub repository](https://github.com/amerkay/wp-give-api-enhanced).
