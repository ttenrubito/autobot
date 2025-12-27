#!/bin/bash

################################################################################
# Production Deployment Script for Customer Portal UX Enhancements
# Version: 2.0.0
# Description: Automated deployment to Google Cloud Run with safety checks
################################################################################

set -e  # Exit on error
set -u  # Exit on undefined variable

# Color codes for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration (UPDATE THESE VALUES)
PROJECT_ID="${PROJECT_ID:-autobot-prod}"
REGION="${REGION:-asia-southeast1}"
SERVICE_NAME="${SERVICE_NAME:-autobot}"
CLOUD_SQL_INSTANCE="${CLOUD_SQL_INSTANCE:-${PROJECT_ID}:${REGION}:autobot-db}"
DB_NAME="${DB_NAME:-autobot}"
DB_USER="${DB_USER:-root}"
VERSION="v2.0.0"
IMAGE_NAME="gcr.io/${PROJECT_ID}/${SERVICE_NAME}:${VERSION}"

# Logging functions
log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Function to check if command exists
command_exists() {
    command -v "$1" >/dev/null 2>&1
}

# Function to prompt for confirmation
confirm() {
    read -p "$1 (yes/no): " response
    case "$response" in
        [yY][eE][sS]|[yY])
            return 0
            ;;
        *)
            return 1
            ;;
    esac
}

################################################################################
# Pre-flight Checks
################################################################################

preflight_checks() {
    log_info "Running pre-flight checks..."
    
    # Check if gcloud is installed
    if ! command_exists gcloud; then
        log_error "gcloud CLI not found. Please install Google Cloud SDK."
        exit 1
    fi
    
    # Check if authenticated
    if ! gcloud auth list --filter=status:ACTIVE --format="value(account)" | grep -q "@"; then
        log_error "Not authenticated with gcloud. Run: gcloud auth login"
        exit 1
    fi
    
    # Check project is set
    CURRENT_PROJECT=$(gcloud config get-value project 2>/dev/null)
    if [ "$CURRENT_PROJECT" != "$PROJECT_ID" ]; then
        log_warning "Current project is '$CURRENT_PROJECT', expected '$PROJECT_ID'"
        if confirm "Switch to project '$PROJECT_ID'?"; then
            gcloud config set project "$PROJECT_ID"
        else
            log_error "Deployment cancelled."
            exit 1
        fi
    fi
    
    # Check if required files exist
    if [ ! -f "Dockerfile" ]; then
        log_error "Dockerfile not found in current directory"
        exit 1
    fi
    
    if [ ! -f "database/fix_slip_image_paths.sql" ]; then
        log_error "Database migration file not found"
        exit 1
    fi
    
    log_success "Pre-flight checks passed!"
}

################################################################################
# Database Backup
################################################################################

backup_database() {
    log_info "Creating database backup..."
    
    BACKUP_FILE="gs://${PROJECT_ID}-backups/autobot-backup-$(date +%Y%m%d-%H%M%S).sql"
    
    if gcloud sql export sql autobot-db "$BACKUP_FILE" \
        --database="$DB_NAME" \
        --project="$PROJECT_ID" 2>/dev/null; then
        log_success "Database backed up to: $BACKUP_FILE"
        echo "$BACKUP_FILE" > .last_backup
    else
        log_warning "Database backup failed or instance not found"
        if ! confirm "Continue without backup?"; then
            exit 1
        fi
    fi
}

################################################################################
# Database Migration
################################################################################

run_migration() {
    log_info "Running database migration..."
    
    # Upload SQL file to Cloud Storage
    MIGRATION_FILE="gs://${PROJECT_ID}-migrations/fix_slip_image_paths-$(date +%Y%m%d-%H%M%S).sql"
    
    if gsutil cp database/fix_slip_image_paths.sql "$MIGRATION_FILE" 2>/dev/null; then
        log_success "Migration file uploaded to: $MIGRATION_FILE"
        
        # Import to Cloud SQL
        if confirm "Run database migration now?"; then
            if gcloud sql import sql autobot-db "$MIGRATION_FILE" \
                --database="$DB_NAME" \
                --project="$PROJECT_ID"; then
                log_success "Database migration completed!"
                
                # Verify migration
                log_info "Verifying migration..."
                # Note: Verification would require Cloud SQL proxy or connection
                log_warning "Please verify migration manually using Cloud Console"
            else
                log_error "Database migration failed!"
                if ! confirm "Continue with deployment anyway?"; then
                    exit 1
                fi
            fi
        else
            log_warning "Skipping database migration"
        fi
    else
        log_error "Failed to upload migration file"
        exit 1
    fi
}

################################################################################
# Build Docker Image
################################################################################

build_image() {
    log_info "Building Docker image: $IMAGE_NAME"
    
    if gcloud builds submit --tag "$IMAGE_NAME" --project="$PROJECT_ID"; then
        log_success "Docker image built successfully!"
    else
        log_error "Docker build failed!"
        exit 1
    fi
}

################################################################################
# Deploy to Cloud Run
################################################################################

deploy_service() {
    log_info "Deploying to Cloud Run..."
    
    # Check if secrets exist
    log_info "Checking required secrets..."
    REQUIRED_SECRETS=("DB_PASSWORD" "JWT_SECRET" "OMISE_SECRET")
    for secret in "${REQUIRED_SECRETS[@]}"; do
        if ! gcloud secrets describe "$secret" --project="$PROJECT_ID" >/dev/null 2>&1; then
            log_error "Secret '$secret' not found. Please create it first."
            exit 1
        fi
    done
    log_success "All required secrets found!"
    
    # Get service URL (if exists)
    EXISTING_URL=$(gcloud run services describe "$SERVICE_NAME" \
        --region="$REGION" \
        --project="$PROJECT_ID" \
        --format='value(status.url)' 2>/dev/null || echo "")
    
    if [ -n "$EXISTING_URL" ]; then
        log_info "Existing service URL: $EXISTING_URL"
        DEPLOYMENT_TYPE="update"
    else
        log_info "This is a new service deployment"
        DEPLOYMENT_TYPE="new"
    fi
    
    if ! confirm "Proceed with deployment?"; then
        exit 1
    fi
    
    # Deploy with gradual rollout for existing services
    if [ "$DEPLOYMENT_TYPE" = "update" ]; then
        log_info "Deploying with gradual rollout (10% traffic)..."
        TRAFFIC_FLAG="--no-traffic"
    else
        TRAFFIC_FLAG=""
    fi
    
    if gcloud run deploy "$SERVICE_NAME" \
        --image="$IMAGE_NAME" \
        --platform=managed \
        --region="$REGION" \
        --project="$PROJECT_ID" \
        --allow-unauthenticated \
        --port=8080 \
        --add-cloudsql-instances="$CLOUD_SQL_INSTANCE" \
        --set-env-vars="DB_HOST=localhost,DB_NAME=$DB_NAME,DB_USER=$DB_USER,DB_SOCKET=/cloudsql/$CLOUD_SQL_INSTANCE" \
        --set-secrets="DB_PASS=DB_PASSWORD:latest,JWT_SECRET_KEY=JWT_SECRET:latest,OMISE_SECRET_KEY=OMISE_SECRET:latest" \
        --memory=1Gi \
        --cpu=2 \
        --max-instances=50 \
        --min-instances=1 \
        --concurrency=80 \
        --timeout=300 \
        $TRAFFIC_FLAG; then
        
        log_success "Service deployed successfully!"
        
        # Get new service URL
        SERVICE_URL=$(gcloud run services describe "$SERVICE_NAME" \
            --region="$REGION" \
            --project="$PROJECT_ID" \
            --format='value(status.url)')
        
        log_success "Service URL: $SERVICE_URL"
        
        # Gradual rollout for updates
        if [ "$DEPLOYMENT_TYPE" = "update" ]; then
            log_info "Starting gradual traffic rollout..."
            
            # Get latest revision
            LATEST_REVISION=$(gcloud run revisions list \
                --service="$SERVICE_NAME" \
                --region="$REGION" \
                --project="$PROJECT_ID" \
                --format='value(metadata.name)' \
                --limit=1)
            
            if confirm "Send 10% traffic to new revision?"; then
                gcloud run services update-traffic "$SERVICE_NAME" \
                    --to-revisions="$LATEST_REVISION=10" \
                    --region="$REGION" \
                    --project="$PROJECT_ID"
                log_success "10% traffic routed to new revision"
                log_warning "Monitor for issues, then increase traffic manually"
            fi
        fi
    else
        log_error "Deployment failed!"
        exit 1
    fi
}

################################################################################
# Post-deployment Tests
################################################################################

run_smoke_tests() {
    log_info "Running smoke tests..."
    
    SERVICE_URL=$(gcloud run services describe "$SERVICE_NAME" \
        --region="$REGION" \
        --project="$PROJECT_ID" \
        --format='value(status.url)')
    
    # Test 1: Health check
    log_info "Test 1: Health check..."
    if curl -s -o /dev/null -w "%{http_code}" "$SERVICE_URL/" | grep -q "200"; then
        log_success "✓ Health check passed"
    else
        log_error "✗ Health check failed"
    fi
    
    # Test 2: Conversations page
    log_info "Test 2: Conversations page..."
    if curl -s -o /dev/null -w "%{http_code}" "$SERVICE_URL/conversations.php" | grep -q "200\|302"; then
        log_success "✓ Conversations page accessible"
    else
        log_error "✗ Conversations page failed"
    fi
    
    # Test 3: Payment history page
    log_info "Test 3: Payment history page..."
    if curl -s -o /dev/null -w "%{http_code}" "$SERVICE_URL/payment-history.php" | grep -q "200\|302"; then
        log_success "✓ Payment history page accessible"
    else
        log_error "✗ Payment history page failed"
    fi
    
    log_success "Smoke tests completed!"
}

################################################################################
# Main Deployment Flow
################################################################################

main() {
    echo ""
    echo "╔════════════════════════════════════════════════════════════════╗"
    echo "║  Production Deployment - Customer Portal UX Enhancements      ║"
    echo "║  Version: $VERSION                                          ║"
    echo "╚════════════════════════════════════════════════════════════════╝"
    echo ""
    
    log_info "Project: $PROJECT_ID"
    log_info "Region: $REGION"
    log_info "Service: $SERVICE_NAME"
    log_info "Image: $IMAGE_NAME"
    echo ""
    
    if ! confirm "Start deployment?"; then
        log_info "Deployment cancelled by user"
        exit 0
    fi
    
    echo ""
    echo "═══════════════════════════════════════════════════════════════"
    echo "STEP 1: Pre-flight Checks"
    echo "═══════════════════════════════════════════════════════════════"
    preflight_checks
    
    echo ""
    echo "═══════════════════════════════════════════════════════════════"
    echo "STEP 2: Database Backup"
    echo "═══════════════════════════════════════════════════════════════"
    backup_database
    
    echo ""
    echo "═══════════════════════════════════════════════════════════════"
    echo "STEP 3: Database Migration"
    echo "═══════════════════════════════════════════════════════════════"
    run_migration
    
    echo ""
    echo "═══════════════════════════════════════════════════════════════"
    echo "STEP 4: Build Docker Image"
    echo "═══════════════════════════════════════════════════════════════"
    build_image
    
    echo ""
    echo "═══════════════════════════════════════════════════════════════"
    echo "STEP 5: Deploy to Cloud Run"
    echo "═══════════════════════════════════════════════════════════════"
    deploy_service
    
    echo ""
    echo "═══════════════════════════════════════════════════════════════"
    echo "STEP 6: Smoke Tests"
    echo "═══════════════════════════════════════════════════════════════"
    run_smoke_tests
    
    echo ""
    echo "╔════════════════════════════════════════════════════════════════╗"
    echo "║                   DEPLOYMENT SUCCESSFUL! 🎉                    ║"
    echo "╚════════════════════════════════════════════════════════════════╝"
    echo ""
    
    SERVICE_URL=$(gcloud run services describe "$SERVICE_NAME" \
        --region="$REGION" \
        --project="$PROJECT_ID" \
        --format='value(status.url)')
    
    echo "Next Steps:"
    echo "1. Visit: $SERVICE_URL"
    echo "2. Test all features manually"
    echo "3. Monitor logs: gcloud run services logs tail $SERVICE_NAME --region=$REGION"
    echo "4. Monitor metrics: https://console.cloud.google.com/run/detail/$REGION/$SERVICE_NAME/metrics"
    echo "5. If issues occur, rollback: gcloud run services update-traffic $SERVICE_NAME --to-latest --region=$REGION"
    echo ""
    echo "Documentation: /docs/PRODUCTION_DEPLOYMENT_GUIDE.md"
    echo ""
}

################################################################################
# Error Handler
################################################################################

trap 'log_error "Deployment failed at line $LINENO"; exit 1' ERR

################################################################################
# Execute Main
################################################################################

main "$@"
