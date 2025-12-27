-- Update Facebook channel config in Cloud SQL
UPDATE customer_channels 
SET config = '{"page_id": "104508009666452", "app_secret": "6c400bdcbf3859a665c9ffac2e3a4d27", "page_access_token": "EAACW5E1WQhsBO6fYh3mupGi0KSwqJ6AeE6y3uMvqtk0uZBLw2rjGu5Laj0kfnxL5yA91Yz8PQEZBqVZAL2lSlqIXY6uGiUHp6eI6ZBsF7f39bUbCZA8hKPqT9LF95qYW2NpbUvWZB5rbXXmPuVXFyYWfVP8jZBN9g2VBB2mD7nqWcOZAlEQn8ZA0wxlLwZCGhSFZCbQZDZD", "app_id": "2616391318710797"}'
WHERE type = 'facebook' 
AND status = 'active' 
AND is_deleted = 0;
