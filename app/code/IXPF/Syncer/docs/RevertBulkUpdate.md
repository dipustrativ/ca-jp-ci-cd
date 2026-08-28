# Revert Bulk Update Documentation

**Version:** 1.0  
**Module:** IXPF_Syncer  
**Feature:** Product Bulk Update Revert  
**Last Updated:** March 3, 2026

---

## Table of Contents

1. [Overview](#overview)
2. [Client-End Documentation](#client-end-documentation)
   - [System Requirements](#system-requirements)
   - [Configuration](#configuration)
   - [How to Use](#how-to-use)
   - [Admin Interface](#admin-interface)
   - [Queue Statuses](#queue-statuses)
   - [Monitoring & Troubleshooting](#monitoring--troubleshooting)
3. [Developer Documentation](#developer-documentation)
   - [Architecture Overview](#architecture-overview)
   - [Database Schema](#database-schema)
   - [API Endpoint](#api-endpoint)
   - [Queue Processing](#queue-processing)
   - [Cron Job](#cron-job)
   - [Class Reference](#class-reference)
   - [Workflow Diagram](#workflow-diagram)
   - [Extension Points](#extension-points)
   - [Testing](#testing)

---

## Overview

The **Revert Bulk Update** feature allows IXPF (external system) to send bulk product updates to Magento asynchronously. Instead of processing updates synchronously, the system queues them and processes them via a cron job, ensuring:

- **Non-blocking API responses**: The API endpoint immediately queues the request and returns
- **Race condition prevention**: Atomic job claiming ensures multiple cron workers don't process the same job
- **Reliability**: Failed updates are logged with detailed error messages
- **Scalability**: Can handle large batches of products without timeout issues
- **Auditability**: Complete audit trail with payload, response, and error tracking

---

## Client-End Documentation

### System Requirements

- Magento 2.4.x or higher
- IXPF_Syncer module installed and enabled
- Cron configured and running
- Valid IXPF authentication token

### Configuration

#### Step 1: Enable Product Bulk Update

1. Navigate to **Stores → Configuration → IXPF Syncer → Product Settings**
2. Set **Enable Product Bulk Update** to `Yes`
3. Click **Save Config**

#### Step 2: Configure Cron Job

1. Navigate to **Stores → Configuration → IXPF Syncer → General Settings**
2. Ensure **Enable Cron Job** is set to `Yes`
3. Click **Save Config**

#### Step 3: Configure IXPF Integration

1. Navigate to **Stores → Configuration → IXPF Syncer → General Settings**
2. Configure the following:
   - **Shop Object ID**: Your unique shop identifier
   - **Product Webhook URL**: The IXPF callback endpoint URL
   - **Product Webhook Secret**: Authentication token for IXPF callbacks
3. Click **Save Config**

#### Step 4: Configure Allowed Attributes

1. Navigate to **Stores → Configuration → IXPF Syncer → Product Settings**
2. In **Allowed Update Attributes**, select which product attributes can be updated via bulk update
3. Click **Save Config**

### How to Use

#### Sending a Bulk Update Request (IXPF)

**Endpoint:**
```
POST /rest/V1/ixpf/products/bulkrevert?store={store_id}&token={webhook_secret}
```

**Request Body:**
```json
{
  "ixpf_batch_id": "batch_12345",
  "products": [
    {
      "id": 101,
      "name": "Updated Product Name",
      "price": 99.99,
      "special_price": 79.99,
      "description": "Updated description",
      "status": 1,
      "website_ids": [1, 2],
      "category_ids": [3, 4, 5]
    },
    {
      "id": 102,
      "name": "Another Product",
      "price": 149.99
    }
  ]
}
```

**Response (Immediate):**
```json
{
  "message": "Revert bulk update products request has been queued successfully."
}
```

#### What Happens Next

1. The request is immediately queued with status `pending`
2. A cron job runs every minute to process pending jobs
3. Products are updated in Magento
4. Results are sent back to IXPF via webhook callback
5. Queue status is updated to `completed` or `failed`

### Admin Interface

#### Accessing the Queue Manager

1. Navigate to **IXPF Syncer → Bulk Update Revert**
2. You'll see a grid with all bulk update queue entries

#### Grid Columns

| Column | Description |
|--------|-------------|
| **ID** | Unique queue entry identifier |
| **Store ID** | Magento store ID where updates will be applied |
| **IXPF Batch ID** | External batch identifier from IXPF |
| **Status** | Current processing status (pending, processing, completed, failed) |
| **Created At** | When the request was queued |
| **Updated At** | Last status update timestamp |
| **Actions** | View details button |

#### Viewing Queue Details

1. Click **View** in the Actions column
2. The detail page shows:
   - **Queue Information**: ID, Store ID, IXPF Batch ID, Status, Timestamps
   - **Payload Data**: Expandable/collapsible JSON viewer showing all products in the batch
   - **Response Result**: Processing results including:
     - `success_product_ids`: IDs of successfully updated products
     - `failed_product_ids`: IDs of products that failed to update
     - `unknown_attributes`: Attributes that were filtered out (not in allowed list)
     - `errors`: Detailed error messages
     - `status`: Final status
   - **Error Message**: Detailed error information if processing failed
   - **IXPF Response**: Response from IXPF callback webhook

#### JSON Viewer Features

- **Expand All**: Expand all nested JSON objects
- **Collapse All**: Collapse all nested JSON objects
- **Syntax Highlighting**: Color-coded JSON for easy reading
- **Click to Expand/Collapse**: Click on individual nodes to expand/collapse them

### Queue Statuses

| Status | Description | Next Action |
|--------|-------------|-------------|
| **pending** | Job is waiting to be processed | Will be picked up by next cron run |
| **processing** | Currently being processed by a cron worker | Wait for completion |
| **completed** | All products processed successfully | No action needed |
| **failed** | One or more products failed to update | Review error messages and retry if needed |

### Monitoring & Troubleshooting

#### Check Cron Status

Verify the cron job is running:
```bash
bin/magento cron:run
```

Check cron schedule:
```bash
bin/magento cron:status | grep ixpf_bulk_update_revert_async_job
```

#### Log Files

The system maintains detailed logs in:
```
var/log/ixpf/bulk_update_revert_YYYY_MM_DD.log
```

Log entries include:
- Queue job claims
- Processing start/end
- Individual product update results
- Error details
- IXPF callback responses

#### Common Issues

**Issue: Jobs stuck in "pending" status**
- **Cause**: Cron not running
- **Solution**: Verify cron is configured and running

**Issue: Products not updating**
- **Cause**: Attributes not in allowed list
- **Solution**: Check `unknown_attributes` in response result and add them to allowed attributes configuration

**Issue: "Product bulk update configuration disable" error**
- **Cause**: Feature not enabled
- **Solution**: Enable in Stores → Configuration → IXPF Syncer → Product Settings

**Issue: Authentication failures**
- **Cause**: Invalid webhook secret token
- **Solution**: Verify token parameter matches configured webhook secret

---

## Developer Documentation

### Architecture Overview

The Revert Bulk Update system follows a **queue-based asynchronous processing** pattern:

```
[IXPF System] 
    ↓ POST request
[BulkRevert Controller] → Queue entry created (status: pending)
    ↓ API response (immediate)
[IXPF System]

[Cron Job (every 1 min)]
    ↓
[ProductUpdate Service]
    ↓ Atomic claim (prevents race conditions)
[Queue Entry] → Status: processing
    ↓
[Process Products]
    ├─ Update Attributes (filtered by allowed list)
    ├─ Sync Websites
    └─ Sync Categories
    ↓
[Send Results to IXPF] → Webhook callback
    ↓
[Update Queue Status] → Status: completed/failed
```

### Database Schema

**Table:** `ixpf_product_bulk_update_queue`

| Column | Type | Description |
|--------|------|-------------|
| `entity_id` | BIGINT | Primary key, auto-increment |
| `store_id` | INT | Magento store ID for updates |
| `ixpf_batch_id` | VARCHAR | External batch identifier from IXPF |
| `payload` | LONGTEXT | JSON array of products to update |
| `response_result` | TEXT | JSON result of processing (success/failed IDs, errors, etc.) |
| `ixpf_response` | TEXT | Response body from IXPF webhook callback |
| `status` | VARCHAR | Current status: pending, processing, completed, failed |
| `error_message` | LONGTEXT | JSON array of error messages |
| `attempts` | INT | Number of processing attempts (reserved for future retry logic) |
| `meta_data` | TEXT | Additional metadata (reserved for future use) |
| `created_at` | DATETIME | Queue entry creation timestamp |
| `updated_at` | DATETIME | Last update timestamp |

**Indexes:**
- Primary key on `entity_id`
- Index on `status` (for efficient pending job queries)

### API Endpoint

**Controller:** `IXPF\Syncer\Controller\V1\Products\BulkRevert`

**Route:** `POST /rest/V1/ixpf/products/bulkrevert`

**Parameters:**
- `store` (query parameter): Store ID (default: 0)
- `token` (query parameter): Authentication token (must match webhook secret)

**Request Body Schema:**
```json
{
  "ixpf_batch_id": "string (required)",
  "products": [
    {
      "id": "integer (required) - Product entity ID",
      "website_ids": "array of integers (optional)",
      "category_ids": "array of integers (optional)",
      "{attribute_code}": "mixed (optional) - Any allowed attribute"
    }
  ]
}
```

**Response:**
- **Success (201):**
  ```json
  {
    "message": "Revert bulk update products request has been queued successfully."
  }
  ```
- **Error (500):**
  ```json
  {
    "error": "Error message"
  }
  ```

**Validations:**
1. Token authentication via `TokenValidator`
2. Product bulk update feature must be enabled
3. `products` array must not be empty
4. `ixpf_batch_id` must be provided

**CSRF Protection:**
- Implements `CsrfAwareActionInterface` with exemption for API requests

### Queue Processing

**Service Class:** `IXPF\Syncer\Services\ProductBulkUpdate\ProductUpdate`

#### Atomic Job Claiming

The `claimNextPendingJob()` method uses database transactions with row-level locking to prevent race conditions:

```php
$connection->beginTransaction();
try {
    // SELECT ... FOR UPDATE locks the row
    $select = $connection->select()
        ->from($tableName)
        ->where('status = ?', QueueStatus::PENDING)
        ->order('created_at ASC')
        ->limit(1)
        ->forUpdate();
    
    $row = $connection->fetchRow($select);
    
    if ($row) {
        // Atomically update status to prevent other workers from claiming
        $connection->update($tableName, 
            ['status' => QueueStatus::PROCESSING],
            ['entity_id = ?' => $row['entity_id']]
        );
    }
    
    $connection->commit();
    return $row;
} catch (Exception $e) {
    $connection->rollBack();
    throw $e;
}
```

#### Attribute Filtering

The system separates product attributes into three categories:

1. **Static Attributes** (never sent to `updateAttributes`):
   - `entity_id`, `product_id`, `id`, `sku`, `type_id`
   - `created_at`, `updated_at`, `attribute_set_id`
   - `media_gallery`, `website_ids`, `category_ids`
   - `image`, `small_image`, `thumbnail`

2. **Allowed Dynamic Attributes**:
   - Configured in admin: Stores → Configuration → IXPF Syncer
   - Retrieved via `AllowAttributes::toOptionArray()`
   - Only these are passed to `ProductAction::updateAttributes()`

3. **Unknown Attributes**:
   - Not in allowed list
   - Reported in `response_result.unknown_attributes`
   - Excluded from updates but logged for visibility

#### Website & Category Sync

**Website Sync:**
```php
protected function syncWebsites($connection, int $productId, array $newWebsiteIds): void
{
    // Get current assignments
    $currentIds = /* fetch from catalog_product_website */;
    
    // Calculate diff
    $toRemove = array_diff($currentIds, $newWebsiteIds);
    $toInsert = array_diff($newWebsiteIds, $currentIds);
    
    // Update using Magento's built-in action
    if ($toRemove) {
        $this->productActionModel->updateWebsites([$productId], $toRemove, 'remove');
    }
    if ($toInsert) {
        $this->productActionModel->updateWebsites([$productId], $toInsert, 'add');
    }
}
```

**Category Sync (Optimized):**
```php
protected function syncCategories($connection, int $productId, array $newCategoryIds): void
{
    $currentIds = /* fetch from catalog_category_product */;
    
    $toRemove = array_diff($currentIds, $newCategoryIds);
    $toInsert = array_diff($newWebsiteIds, $currentIds);
    
    // Bulk DELETE with IN() clause
    if ($toRemove) {
        $connection->delete($table, [
            'product_id = ?' => $productId,
            'category_id IN (?)' => $toRemove,
        ]);
    }
    
    // Bulk INSERT instead of row-by-row
    if ($toInsert) {
        $connection->insertMultiple($table, 
            array_map(fn($catId) => [
                'product_id' => $productId, 
                'category_id' => $catId
            ], $toInsert)
        );
    }
}
```

#### Status Resolution

```php
private function resolveFinalStatus(array $successIds, array $failedIds, int $total): string
{
    if (empty($failedIds)) {
        return QueueStatus::COMPLETED; // All succeeded
    }
    
    if (count($failedIds) === $total) {
        return QueueStatus::FAILED; // All failed
    }
    
    return QueueStatus::FAILED; // Partial failure - mark as failed for visibility
}
```

**Note:** Partial failures are marked as `FAILED` to ensure they appear in monitoring dashboards. Check `response_result` for details on which products succeeded/failed.

### Cron Job

**Class:** `IXPF\Syncer\Cron\BulkRevert`

**Schedule:** Every 1 minute (`*/1 * * * *`)

**Configuration:** `app/code/IXPF/Syncer/etc/crontab.xml`

```xml
<job name="ixpf_bulk_update_revert_async_job" 
     instance="IXPF\Syncer\Cron\BulkRevert" 
     method="execute">
    <schedule>*/1 * * * *</schedule>
</job>
```

**Execution Flow:**
1. Check if cron job is enabled in configuration
2. Call `ProductUpdate::process()`
3. Process claims and processes one pending job
4. Log any exceptions

**Note:** The cron processes **one job per execution** to prevent timeout issues. High-frequency scheduling (every minute) ensures timely processing.

### Class Reference

#### `IXPF\Syncer\Services\ProductBulkUpdate\ProductUpdate`

**Dependencies:**
- `ProductAction` - Magento's product attribute update service
- `Action` - Product website/category sync
- `AllowAttributes` - Allowed attributes configuration
- `ResourceConnection` - Direct database access
- `ClientInterface` - HTTP client for IXPF callbacks
- `BulkUpdateRevertLogger` - Logging service
- `Config` - Module configuration helper

**Public Methods:**

| Method | Return | Description |
|--------|--------|-------------|
| `process()` | `array` | Main entry point: claims next job, processes it, sends results to IXPF |
| `claimNextPendingJob()` | `array\|false` | Atomically claims next pending job using row-level locking |
| `splitProductAttributes(array $data)` | `array` | Splits attributes into filtered (allowed) and unknown |

**Protected Methods:**

| Method | Parameters | Description |
|--------|------------|-------------|
| `processProduct()` | `array $productData, int $storeId` | Processes single product: updates attributes, websites, categories |
| `updateQueueStatus()` | `int $queueId, string $status, array $errors, array $result` | Updates queue entry status |
| `saveIxpfResponse()` | `int $queueId, ?string $response, ?string $error` | Saves IXPF webhook callback response |
| `sendResultToIXPF()` | `string $ixpfBatchId, int $queueId, array $resultData` | Sends processing results to IXPF via webhook |
| `syncWebsites()` | `$connection, int $productId, array $websiteIds` | Syncs product-website assignments |
| `syncCategories()` | `$connection, int $productId, array $categoryIds` | Syncs product-category assignments |

#### `IXPF\Syncer\Services\ProductBulkUpdate\QueueStatus`

**Constants:**
```php
const PENDING = 'pending';
const PROCESSING = 'processing';
const COMPLETED = 'completed';
const FAILED = 'failed';
```

#### `IXPF\Syncer\Controller\V1\Products\BulkRevert`

**Implements:**
- `HttpPostActionInterface` - POST-only endpoint
- `CsrfAwareActionInterface` - CSRF exemption for API

**Methods:**
- `execute()` - Handles incoming bulk revert requests
- `createCsrfValidationException()` - Returns null (exempts API from CSRF)
- `validateForCsrf()` - Returns true (allows request)

#### `IXPF\Syncer\Cron\BulkRevert`

**Method:**
- `execute()` - Called by cron scheduler every minute

### Workflow Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                         IXPF System                             │
└────────────────────────┬────────────────────────────────────────┘
                         │
                         │ POST /rest/V1/ixpf/products/bulkrevert
                         ▼
┌─────────────────────────────────────────────────────────────────┐
│              BulkRevert Controller                              │
│  - Validate token                                               │
│  - Validate payload                                             │
│  - Check configuration                                          │
└────────────────────────┬────────────────────────────────────────┘
                         │
                         │ Insert into queue
                         ▼
┌─────────────────────────────────────────────────────────────────┐
│          ixpf_product_bulk_update_queue (DB)                    │
│  Status: PENDING                                                │
└────────────────────────┬────────────────────────────────────────┘
                         │
                         │ Return 201 (queued)
                         ▼
┌─────────────────────────────────────────────────────────────────┐
│                         IXPF System                             │
│  (Receives immediate acknowledgment)                            │
└─────────────────────────────────────────────────────────────────┘

        ┌────────────────────────────────────────┐
        │    Cron: Every 1 minute                │
        └──────────────┬─────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────────┐
│              ProductUpdate::process()                           │
│  1. claimNextPendingJob() - Atomic SELECT FOR UPDATE            │
│  2. Status → PROCESSING                                         │
└────────────────────────┬────────────────────────────────────────┘
                         │
                         │ For each product
                         ▼
┌─────────────────────────────────────────────────────────────────┐
│            ProductUpdate::processProduct()                      │
│  1. Split attributes (filtered vs unknown)                      │
│  2. Begin transaction                                           │
│  3. updateAttributes() - filtered attributes only               │
│  4. syncWebsites()                                              │
│  5. syncCategories()                                            │
│  6. Commit transaction                                          │
└────────────────────────┬────────────────────────────────────────┘
                         │
                         │ Aggregate results
                         ▼
┌─────────────────────────────────────────────────────────────────┐
│          ProductUpdate::sendResultToIXPF()                      │
│  POST {webhook_url}?token={secret}                              │
│  Body: {                                                        │
│    shop_object_id, type, batch_id, result                       │
│  }                                                              │
└────────────────────────┬────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────┐
│       ProductUpdate::updateQueueStatus()                        │
│  Status: COMPLETED or FAILED                                    │
│  Save: response_result, ixpf_response, error_message            │
└─────────────────────────────────────────────────────────────────┘
```

### Extension Points

#### Custom Attribute Handling

To add custom logic for specific attributes, override `processProduct()`:

```php
namespace Vendor\Module\Services\ProductBulkUpdate;

use IXPF\Syncer\Services\ProductBulkUpdate\ProductUpdate as BaseProductUpdate;

class ProductUpdate extends BaseProductUpdate
{
    protected function processProduct(array $productData, int $storeId): array
    {
        // Pre-processing logic
        if (isset($productData['custom_attribute'])) {
            // Custom handling
        }
        
        // Call parent
        $result = parent::processProduct($productData, $storeId);
        
        // Post-processing logic
        
        return $result;
    }
}
```

Then configure in `di.xml`:
```xml
<preference for="IXPF\Syncer\Services\ProductBulkUpdate\ProductUpdate" 
            type="Vendor\Module\Services\ProductBulkUpdate\ProductUpdate" />
```

#### Custom Status Resolution

Override `resolveFinalStatus()` to implement custom status logic:

```php
private function resolveFinalStatus(array $successIds, array $failedIds, int $total): string
{
    $successRate = count($successIds) / $total;
    
    if ($successRate >= 0.95) {
        return QueueStatus::COMPLETED; // 95%+ success = completed
    }
    
    if ($successRate >= 0.75) {
        return 'partial_success'; // Custom status
    }
    
    return QueueStatus::FAILED;
}
```

#### Event Observers

You can observe standard Magento events during processing:
- `catalog_product_save_before`
- `catalog_product_save_after`
- Custom events can be dispatched in override

#### Custom Loggers

Inject your own logger:

```xml
<type name="IXPF\Syncer\Services\ProductBulkUpdate\ProductUpdate">
    <arguments>
        <argument name="logger" xsi:type="object">Vendor\Module\Logger\CustomLogger</argument>
    </arguments>
</type>
```

### Testing

#### Unit Testing

Test the attribute splitting logic:

```php
public function testSplitProductAttributes()
{
    $productData = [
        'id' => 101,
        'name' => 'Test Product',
        'price' => 99.99,
        'sku' => 'TEST-SKU',
        'unknown_attr' => 'value',
        'website_ids' => [1, 2],
    ];
    
    $result = $this->productUpdate->splitProductAttributes($productData);
    
    // 'name' and 'price' should be in filtered (if allowed)
    // 'id', 'sku', 'website_ids' should be excluded from both
    // 'unknown_attr' should be in unknown
    
    $this->assertArrayHasKey('filtered', $result);
    $this->assertArrayHasKey('unknown', $result);
    $this->assertArrayNotHasKey('id', $result['filtered']);
    $this->assertArrayNotHasKey('sku', $result['filtered']);
}
```

#### Integration Testing

Test the full workflow:

```php
public function testBulkRevertWorkflow()
{
    // 1. Create test products
    $product1 = $this->createProduct(['sku' => 'TEST-001']);
    $product2 = $this->createProduct(['sku' => 'TEST-002']);
    
    // 2. Send bulk revert request
    $payload = [
        'ixpf_batch_id' => 'test-batch-123',
        'products' => [
            ['id' => $product1->getId(), 'name' => 'Updated Name 1'],
            ['id' => $product2->getId(), 'name' => 'Updated Name 2'],
        ],
    ];
    
    $response = $this->sendPostRequest('/rest/V1/ixpf/products/bulkrevert', $payload);
    $this->assertEquals(201, $response->getStatusCode());
    
    // 3. Verify queue entry created
    $queueEntry = $this->getQueueEntryByBatchId('test-batch-123');
    $this->assertEquals(QueueStatus::PENDING, $queueEntry->getStatus());
    
    // 4. Process via cron
    $this->cronJob->execute();
    
    // 5. Verify products updated
    $product1->load($product1->getId());
    $this->assertEquals('Updated Name 1', $product1->getName());
    
    // 6. Verify queue status
    $queueEntry->load($queueEntry->getId());
    $this->assertEquals(QueueStatus::COMPLETED, $queueEntry->getStatus());
}
```

#### Manual Testing

1. **Queue a request:**
   ```bash
   curl -X POST "https://yourstore.com/rest/V1/ixpf/products/bulkrevert?store=0&token=YOUR_TOKEN" \
     -H "Content-Type: application/json" \
     -d '{
       "ixpf_batch_id": "manual-test-001",
       "products": [
         {
           "id": 1,
           "name": "Test Product Update",
           "price": 99.99
         }
       ]
     }'
   ```

2. **Verify queue entry:**
   ```sql
   SELECT * FROM ixpf_product_bulk_update_queue 
   WHERE ixpf_batch_id = 'manual-test-001';
   ```

3. **Process via cron:**
   ```bash
   bin/magento cron:run --group default
   ```

4. **Check logs:**
   ```bash
   tail -f var/log/ixpf/bulk_update_revert_YYYY_MM_DD.log
   ```

5. **Verify in admin:**
   - Navigate to IXPF Syncer → Bulk Update Revert
   - Find your batch
   - Click View to see detailed results

---

## Appendix

### Response Result Schema

```json
{
  "success_product_ids": [101, 102, 103],
  "failed_product_ids": [104],
  "unknown_attributes": ["custom_attribute_1", "unknown_field"],
  "errors": [
    "Product ID 104: Integrity constraint violation"
  ],
  "status": "failed"
}
```

### IXPF Webhook Callback

**Endpoint:** Configured in `product_webhook_url`

**Method:** POST

**Parameters:**
- `token` (query): Configured webhook secret

**Body:**
```json
{
  "shop_object_id": "your-shop-id",
  "type": "revert_bulk_update",
  "batch_id": "batch_12345",
  "result": "{...response_result JSON...}"
}
```

### Common Configuration Paths

| Configuration | Path |
|---------------|------|
| Enable Product Bulk Update | `ixpf_syncer/product_update/enable_bulk_update` |
| Enable Cron Job | `ixpf_syncer/general/enable_cron_job` |
| Shop Object ID | `ixpf_syncer/general/shop_object_id` |
| Product Webhook URL | `ixpf_syncer/product_update/webhook_url` |
| Product Webhook Secret | `ixpf_syncer/product_update/webhook_secret` |
| Allowed Attributes | `ixpf_syncer/product_update/allow_attributes` |

### Performance Considerations

**Batch Size:**
- Recommended: 100-500 products per batch
- Maximum tested: 1000 products per batch
- Larger batches increase processing time per cron run

**Cron Frequency:**
- Default: Every 1 minute
- Can be adjusted in `crontab.xml`
- More frequent = faster processing, higher server load

**Optimization Tips:**
1. Use database connection pooling
2. Ensure proper indexing on queue table
3. Monitor cron execution time
4. Scale horizontally by running multiple cron workers
5. Consider separating into priority queues for time-sensitive updates

### Security Considerations

1. **Token Authentication:** Always use HTTPS for API endpoints
2. **Token Storage:** Store webhook secrets encrypted in database
3. **Input Validation:** Payload is validated before queueing
4. **SQL Injection:** Uses parameterized queries throughout
5. **Access Control:** Admin interface protected by ACL resource `IXPF_Syncer::bulk_update_revert`

---

**For Support:** Contact IXPF development team or refer to the module's GitHub repository.

**Version History:**
- 1.0 (March 2026): Initial documentation release

