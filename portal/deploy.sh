#!/bin/bash
#
# Puke Portal - Deployment Script
#
# Usage: ./deploy.sh [--no-migrate] [--no-cache] [--restart-php]
#
# This script:
#   1. Pulls latest code from git
#   2. Clears caches
#   3. Runs database migrations
#   4. Updates service worker version
#   5. Sets proper permissions
#   6. Optionally restarts PHP-FPM
#

set -e

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PORTAL_ROOT="$SCRIPT_DIR"
PUBLIC_DIR="$PORTAL_ROOT/public"
DATA_DIR="$PORTAL_ROOT/data"
CONFIG_DIR="$PORTAL_ROOT/config"
LOG_FILE="$DATA_DIR/logs/deploy.log"

# Default options
DO_MIGRATE=true
DO_CACHE=true
RESTART_PHP=false

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --no-migrate)
            DO_MIGRATE=false
            shift
            ;;
        --no-cache)
            DO_CACHE=false
            shift
            ;;
        --restart-php)
            RESTART_PHP=true
            shift
            ;;
        -h|--help)
            echo "Usage: ./deploy.sh [--no-migrate] [--no-cache] [--restart-php]"
            echo ""
            echo "Options:"
            echo "  --no-migrate    Skip database migrations"
            echo "  --no-cache      Skip cache clearing"
            echo "  --restart-php   Restart PHP-FPM after deployment"
            echo "  -h, --help      Show this help message"
            exit 0
            ;;
        *)
            echo -e "${RED}Unknown option: $1${NC}"
            exit 1
            ;;
    esac
done

# Logging function
log() {
    local message="[$(date '+%Y-%m-%d %H:%M:%S')] $1"
    echo -e "$message"
    echo "$message" >> "$LOG_FILE"
}

# Error handling
error_exit() {
    log "${RED}ERROR: $1${NC}"
    exit 1
}

# Check if we're in the right directory
check_directory() {
    if [[ ! -f "$PORTAL_ROOT/composer.json" ]]; then
        error_exit "Not in portal root directory. Please run from portal directory."
    fi
}

# Ensure log directory exists
ensure_log_dir() {
    mkdir -p "$DATA_DIR/logs"
}

# Pull latest code from git
git_pull() {
    log "${GREEN}Pulling latest code from git...${NC}"

    cd "$PORTAL_ROOT"

    # Stash any local changes
    if [[ -n $(git status --porcelain) ]]; then
        log "${YELLOW}Stashing local changes...${NC}"
        git stash
    fi

    # Pull latest
    git pull origin main

    log "Git pull completed."
}

# Clear caches
clear_caches() {
    if [[ "$DO_CACHE" == false ]]; then
        log "${YELLOW}Skipping cache clear...${NC}"
        return
    fi

    log "${GREEN}Clearing caches...${NC}"

    # Clear PHP OPcache if available
    if command -v php &> /dev/null; then
        php -r "if (function_exists('opcache_reset')) { opcache_reset(); echo 'OPcache cleared.'; }" 2>/dev/null || true
    fi

    # Clear any application cache files
    if [[ -d "$DATA_DIR/cache" ]]; then
        rm -rf "$DATA_DIR/cache/*"
        log "Application cache cleared."
    fi

    # Clear any temporary files
    if [[ -d "$PORTAL_ROOT/tmp" ]]; then
        rm -rf "$PORTAL_ROOT/tmp/*"
        log "Temporary files cleared."
    fi

    log "Caches cleared."
}

# Run database migrations
run_migrations() {
    if [[ "$DO_MIGRATE" == false ]]; then
        log "${YELLOW}Skipping database migrations...${NC}"
        return
    fi

    log "${GREEN}Running database migrations...${NC}"

    # Check if schema.sql exists
    if [[ ! -f "$DATA_DIR/schema.sql" ]]; then
        log "${YELLOW}No schema.sql found. Skipping migrations.${NC}"
        return
    fi

    # Run migrations using PHP
    php -r "
        \$dbPath = '$DATA_DIR/portal.db';
        \$schemaFile = '$DATA_DIR/schema.sql';

        try {
            \$db = new PDO('sqlite:' . \$dbPath);
            \$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Check if tables exist
            \$tables = \$db->query(\"SELECT name FROM sqlite_master WHERE type='table'\");
            \$existingTables = \$tables->fetchAll(PDO::FETCH_COLUMN);

            if (empty(\$existingTables)) {
                echo 'Initializing database from schema.sql...' . PHP_EOL;
                \$schema = file_get_contents(\$schemaFile);
                \$db->exec(\$schema);
                echo 'Database initialized successfully.' . PHP_EOL;
            } else {
                echo 'Database already initialized. ' . count(\$existingTables) . ' tables found.' . PHP_EOL;
            }
        } catch (Exception \$e) {
            echo 'Migration error: ' . \$e->getMessage() . PHP_EOL;
            exit(1);
        }
    "

    log "Database migrations completed."
}

# Update service worker version
update_sw_version() {
    log "${GREEN}Updating service worker version...${NC}"

    SW_FILE="$PUBLIC_DIR/sw.js"

    if [[ ! -f "$SW_FILE" ]]; then
        log "${YELLOW}Service worker not found. Skipping version update.${NC}"
        return
    fi

    # Generate new version based on timestamp
    NEW_VERSION=$(date '+%Y%m%d%H%M%S')

    # Update the CACHE_VERSION in sw.js
    if grep -q "CACHE_VERSION" "$SW_FILE"; then
        sed -i.bak "s/CACHE_VERSION = '[^']*'/CACHE_VERSION = '$NEW_VERSION'/" "$SW_FILE"
        rm -f "$SW_FILE.bak"
        log "Service worker version updated to: $NEW_VERSION"
    else
        log "${YELLOW}CACHE_VERSION not found in sw.js. Manual update may be required.${NC}"
    fi
}

# Set proper permissions
set_permissions() {
    log "${GREEN}Setting file permissions...${NC}"

    # Set directory permissions
    find "$PORTAL_ROOT" -type d -exec chmod 755 {} \;

    # Set file permissions
    find "$PORTAL_ROOT" -type f -exec chmod 644 {} \;

    # Make scripts executable
    chmod +x "$PORTAL_ROOT/deploy.sh"
    chmod +x "$PORTAL_ROOT/artisan" 2>/dev/null || true

    # Set data directory permissions (writable by web server)
    chmod 775 "$DATA_DIR"
    chmod 775 "$DATA_DIR/logs" 2>/dev/null || true
    chmod 664 "$DATA_DIR/portal.db" 2>/dev/null || true
    chmod 664 "$DATA_DIR/portal.db-wal" 2>/dev/null || true
    chmod 664 "$DATA_DIR/portal.db-shm" 2>/dev/null || true

    # Config file should be readable but not world-readable
    chmod 640 "$CONFIG_DIR/config.php" 2>/dev/null || true

    # Set ownership (assuming www-data is the web server user)
    # Uncomment and adjust as needed for your server
    # chown -R www-data:www-data "$DATA_DIR"
    # chown www-data:www-data "$CONFIG_DIR/config.php"

    log "Permissions set."
}

# Restart PHP-FPM if requested
restart_php_fpm() {
    if [[ "$RESTART_PHP" == false ]]; then
        return
    fi

    log "${GREEN}Restarting PHP-FPM...${NC}"

    # Try different service managers
    if command -v systemctl &> /dev/null; then
        sudo systemctl restart php-fpm || sudo systemctl restart php8.2-fpm || sudo systemctl restart php8.1-fpm || log "${YELLOW}Could not restart PHP-FPM via systemctl${NC}"
    elif command -v service &> /dev/null; then
        sudo service php-fpm restart || sudo service php8.2-fpm restart || sudo service php8.1-fpm restart || log "${YELLOW}Could not restart PHP-FPM via service${NC}"
    else
        log "${YELLOW}No service manager found. Please restart PHP-FPM manually.${NC}"
    fi

    log "PHP-FPM restart completed."
}

# Verify deployment
verify_deployment() {
    log "${GREEN}Verifying deployment...${NC}"

    local errors=0

    # Check config file exists
    if [[ ! -f "$CONFIG_DIR/config.php" ]]; then
        log "${RED}WARNING: config.php not found. Copy from config.example.php${NC}"
        ((errors++))
    fi

    # Check database exists
    if [[ ! -f "$DATA_DIR/portal.db" ]]; then
        log "${YELLOW}WARNING: Database not found. Run setup.php or first request will create it.${NC}"
    fi

    # Check public directory
    if [[ ! -f "$PUBLIC_DIR/index.php" ]]; then
        log "${RED}ERROR: index.php not found in public directory${NC}"
        ((errors++))
    fi

    # Check service worker
    if [[ ! -f "$PUBLIC_DIR/sw.js" ]]; then
        log "${YELLOW}WARNING: Service worker not found.${NC}"
    fi

    if [[ $errors -eq 0 ]]; then
        log "${GREEN}Deployment verification passed.${NC}"
    else
        log "${RED}Deployment verification found $errors error(s).${NC}"
    fi
}

# Main deployment process
main() {
    log "======================================"
    log "Puke Portal Deployment Started"
    log "======================================"

    ensure_log_dir
    check_directory
    git_pull
    clear_caches
    run_migrations
    update_sw_version
    set_permissions
    restart_php_fpm
    verify_deployment

    log "======================================"
    log "${GREEN}Deployment completed successfully!${NC}"
    log "======================================"
}

# Run main function
main
