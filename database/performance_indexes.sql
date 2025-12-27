-- ============================================
-- Performance Optimization Indexes
-- ============================================

-- API Usage Logs - for fast querying by date, service, and customer
CREATE INDEX IF NOT EXISTS idx_api_usage_date_service 
ON api_usage_logs(created_at, api_type, customer_service_id);

CREATE INDEX IF NOT EXISTS idx_api_usage_customer_date 
ON api_usage_logs(customer_service_id, created_at);

-- Customer Services - for user lookups
CREATE INDEX IF NOT EXISTS idx_customer_service_user_status 
ON customer_services(user_id, status);

-- Bot Chat Logs - for recent message queries
CREATE INDEX IF NOT EXISTS idx_bot_chat_customer_date 
ON bot_chat_logs(customer_service_id, created_at);

-- Subscriptions - for active subscription queries
CREATE INDEX IF NOT EXISTS idx_subscriptions_user_status 
ON subscriptions(user_id, status);

CREATE INDEX IF NOT EXISTS idx_subscriptions_plan 
ON subscriptions(plan_id, status);

-- Invoices - for recent invoice queries
CREATE INDEX IF NOT EXISTS idx_invoices_user_date 
ON invoices(user_id, created_at);

CREATE INDEX IF NOT EXISTS idx_invoices_status 
ON invoices(status, created_at);

-- Transactions - for payment tracking
CREATE INDEX IF NOT EXISTS idx_transactions_invoice 
ON transactions(invoice_id, status);

CREATE INDEX IF NOT EXISTS idx_transactions_user 
ON transactions(user_id, created_at);

-- Activity Logs - for recent activity queries
CREATE INDEX IF NOT EXISTS idx_activity_user_date 
ON activity_logs(user_id, created_at);

-- API Keys - for faster lookups
CREATE INDEX IF NOT EXISTS idx_api_keys_user 
ON api_keys(user_id, is_active);

-- Customer API Access - for permission checks
CREATE INDEX IF NOT EXISTS idx_customer_api_user_service 
ON customer_api_access(user_id, service_code, is_enabled);

-- Admin Users - for login
CREATE INDEX IF NOT EXISTS idx_admin_username_active 
ON admin_users(username, is_active);

-- ============================================
-- Analyze tables for optimal query planning
-- ============================================
ANALYZE TABLE users;
ANALYZE TABLE customer_services;
ANALYZE TABLE api_usage_logs;
ANALYZE TABLE bot_chat_logs;
ANALYZE TABLE subscriptions;
ANALYZE TABLE invoices;
ANALYZE TABLE transactions;
ANALYZE TABLE api_keys;
ANALYZE TABLE customer_api_access;
