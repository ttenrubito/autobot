# API Gateway Testing Examples for n8n

This guide shows how to test the AI Automation API Gateway with n8n or any HTTP client.

## Getting Your API Key

1. Login to Customer Portal: `http://localhost/autobot/public/`
2. Navigate to **API Docs** page
3. Copy your API key (format: `ak_xxxxxxxxxxxxxxxx`)

## Testing with cURL

### Google Vision API - Label Detection

```bash
# Detect labels in an image
curl -X POST http://localhost/autobot/api/gateway/vision/labels \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_API_KEY_HERE" \
  -d '{
    "image": {
      "content": "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=="
    }
  }'
```

### Google Vision API - Text Detection (OCR)

```bash
curl -X POST http://localhost/autobot/api/gateway/vision/text \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_API_KEY_HERE" \
  -d '{
    "image": {
      "content": "BASE64_ENCODED_IMAGE"
    }
  }'
```

### Google Natural Language - Sentiment Analysis

```bash
curl -X POST http://localhost/autobot/api/gateway/language/sentiment \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_API_KEY_HERE" \
  -d '{
    "text": "I absolutely love this product! It is amazing and works perfectly."
  }'
```

### Google Natural Language - Entity Extraction

```bash
curl -X POST http://localhost/autobot/api/gateway/language/entities \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_API_KEY_HERE" \
  -d '{
    "text": "Google was founded in California by Larry Page and Sergey Brin in 1998."
  }'
```

## Testing with n8n

### 1. Create HTTP Request Node

1. Add **HTTP Request** node to your workflow
2. Set method to `POST`
3. Enter URL: `http://localhost/autobot/api/gateway/vision/labels`

### 2. Add Headers

Click "Add Parameter" → "Headers" → "Add Header":

| Name | Value |
|------|-------|
| `Content-Type` | `application/json` |
| `X-API-Key` | `YOUR_API_KEY_HERE` |

### 3. Add Request Body

Select "JSON" and enter:

```json
{
  "image": {
    "content": "{{$binary.data.data}}"
  }
}
```

Or for text analysis:

```json
{
  "text": "{{$json.text}}"
}
```

### 4. Example Workflows

#### Image Analysis Workflow

```
[Webhook] → [Read Binary File] → [HTTP Request: Vision API] → [Parse Results]
```

#### Text Analysis Workflow

```
[Webhook] → [HTTP Request: Language API] → [Process Sentiment] → [Store in DB]
```

## Base64 Encoding Images

### Using Node.js

```javascript
const fs = require('fs');
const imageBuffer = fs.readFileSync('image.jpg');
const base64Image = imageBuffer.toString('base64');
console.log(base64Image);
```

### Using Python

```python
import base64

with open('image.jpg', 'rb') as image_file:
    base64_image = base64.b64encode(image_file.read()).decode('utf-8')
    print(base64_image)
```

### Using PHP

```php
$imageData = file_get_contents('image.jpg');
$base64Image = base64_encode($imageData);
echo $base64Image;
```

## Response Examples

### Vision API - Labels

```json
{
  "responses": [
    {
      "labelAnnotations": [
        {
          "mid": "/m/01g317",
          "description": "person",
          "score": 0.98,
          "topicality": 0.98
        },
        {
          "mid": "/m/05s2s",
          "description": "tree",
          "score": 0.95,
          "topicality": 0.95
        }
      ]
    }
  ]
}
```

### Language API - Sentiment

```json
{
  "documentSentiment": {
    "score": 0.8,
    "magnitude": 0.8
  },
  "language": "en",
  "sentences": [
    {
      "text": {
        "content": "I  absolutely love this product!"
      },
      "sentiment": {
        "score": 0.9,
        "magnitude": 0.9
      }
    }
  ]
}
```

## Error Handling

### Common Errors

| Code | Meaning | Solution |
|------|---------|----------|
| 401 | Invalid API key | Check your API key is correct |
| 403 | Service not enabled | Contact admin to enable service |
| 413 | Image/text too large | Reduce file size |
| 429 | Rate limit exceeded | Wait and try again |
| 503 | Service unavailable | Service is temporarily disabled |

### Error Response Format

```json
{
  "success": false,
  "message": "Error description",
  "error_code": 429
}
```

## Rate Limits

Check your rate limits in the Customer Portal → API Docs page.

Default limits:
- Vision APIs: 1000 requests/day
- Language APIs: 2000 requests/day

Monitor your usage in the **Usage** page.

## Advanced Features

### Async Processing (Coming Soon)

For long-running requests, use async mode:

```bash
curl -X POST http://localhost/autobot/api/gateway/vision/labels?async=true \
  -H "X-API-Key: YOUR_KEY" \
  -H "X-Webhook-URL: https://your-n8n-instance.com/webhook/callback" \
  -d '{ "image": { "content": "..." } }'
```

Response:
```json
{
  "job_id": "job_abc123",
  "status": "processing"
}
```

Results will be sent to your webhook when ready.

## Monitoring

View real-time logs:

```bash
tail -f /opt/lampp/htdocs/autobot/logs/app-$(date +%Y-%m-%d).log | jq
```

Check health endpoint:

```bash
curl http://localhost/autobot/api/health.php
```

## Support

- API Documentation: `http://localhost/autobot/public/api-docs.html`
- Usage Statistics: `http://localhost/autobot/public/usage.html`
- Contact: Your admin panel

## Sample Images (Base64)

**1x1 Red Pixel:**
```
iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==
```

**1x1 Blue Pixel:**
```
iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==
```

For real testing, use actual images from your use case.
