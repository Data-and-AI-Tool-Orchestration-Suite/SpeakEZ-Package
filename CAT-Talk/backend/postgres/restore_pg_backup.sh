#!/bin/bash

# PostgreSQL Docker Restore Script
# Usage: ./restore_postgres.sh [backup_file] [container_name]
# Restores PostgreSQL database from backup file

set -euo pipefail

# CONFIG SECTION - Core paths
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${SCRIPT_DIR}/../../.env"

# Color codes for console output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Global variables
BACKUP_FILE=""
TEMP_FILE=""
START_TIME=""
CONTAINER_NAME=""
BACKUP_DIR=""
LOG_FILE=""
RESTORE_TIMEOUT=""
EMAIL_NOTIFICATIONS=""
EMAIL_TO=""
SYSLOG_TAG=""
CONFIRMATION_REQUIRED=""

# Function to log messages with timestamp
log() {
    local level="$1"
    shift
    local message="$*"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')

    # Ensure log directory exists
    mkdir -p "$(dirname "$LOG_FILE")"

    # Log to file
    echo "[$timestamp] [$level] $message" >> "$LOG_FILE" || true

    # Log to console with colors
    case "$level" in
        "ERROR")
            echo -e "${RED}[$timestamp] [ERROR] $message${NC}" >&2
            ;;
        "WARN")
            echo -e "${YELLOW}[$timestamp] [WARN] $message${NC}"
            ;;
        "INFO")
            echo -e "${GREEN}[$timestamp] [INFO] $message${NC}"
            ;;
        "DEBUG")
            echo -e "${BLUE}[$timestamp] [DEBUG] $message${NC}"
            ;;
        *)
            echo "[$timestamp] [$level] $message"
            ;;
    esac

    # Log to syslog
    logger -t "$SYSLOG_TAG" "[$level] $message"
}

# Function to send email notifications
send_email() {
    local subject="$1"
    local body="$2"

    if [[ "$EMAIL_NOTIFICATIONS" == "true" && -n "$EMAIL_TO" ]]; then
        if command -v mail >/dev/null 2>&1; then
            echo "$body" | mail -s "$subject" "$EMAIL_TO"
            log "INFO" "Email notification sent to $EMAIL_TO"
        else
            log "WARN" "Email notifications enabled but 'mail' command not found"
        fi
    fi
}

# Function to cleanup on exit
cleanup() {
    local exit_code=$?

    if [[ -n "$TEMP_FILE" && -f "$TEMP_FILE" ]]; then
        log "DEBUG" "Cleaning up temporary file: $TEMP_FILE"
        rm -f "$TEMP_FILE"
    fi

    if [[ $exit_code -ne 0 ]]; then
        log "ERROR" "Restore failed with exit code $exit_code"
        send_email "PostgreSQL Restore Failed" "Restore process failed. Check logs at $LOG_FILE"
    fi

    exit $exit_code
}

# Function to handle signals
signal_handler() {
    log "WARN" "Received interrupt signal, cleaning up..."
    cleanup
}

# Set up signal trapping
trap signal_handler SIGINT SIGTERM
trap cleanup EXIT

# Function to check if Docker daemon is running
check_docker() {
    log "DEBUG" "Checking Docker daemon connectivity"
    if ! docker info >/dev/null 2>&1; then
        log "ERROR" "Docker daemon is not running or not accessible"
        exit 1
    fi
    log "DEBUG" "Docker daemon is accessible"
}

# Function to check if container exists and is running
check_container() {
    log "DEBUG" "Checking container status: $CONTAINER_NAME"
    if ! docker ps --format "table {{.Names}}" | grep -q "^${CONTAINER_NAME}$"; then
        log "ERROR" "Container '$CONTAINER_NAME' is not running"
        exit 1
    fi
    log "DEBUG" "Container '$CONTAINER_NAME' is running"
}

# Function to load environment variables
load_env() {
    log "DEBUG" "Loading environment variables from $ENV_FILE"

    # Environment already loaded above for argument parsing
    # Just validate and set remaining variables

    # Validate required variables (PROJECT_NAME already validated above)
    if [[ -z "${POSTGRES_DB:-}" || -z "${POSTGRES_USER:-}" || -z "${POSTGRES_PASSWORD:-}" ]]; then
        log "ERROR" "Missing required environment variables (POSTGRES_DB, POSTGRES_USER, POSTGRES_PASSWORD)"
        exit 1
    fi

    # Set defaults and configure from env
    POSTGRES_PORT="${POSTGRES_PORT:-5432}"

    # Use CONTAINER_NAME_ARG if provided, otherwise use default
    if [[ -n "${CONTAINER_NAME_ARG:-}" ]]; then
        CONTAINER_NAME="$CONTAINER_NAME_ARG"
    else
        CONTAINER_NAME="${PROJECT_NAME}_postgres"
    fi

    LOG_FILE="${LOG_FILE:-${BACKUP_DIR}/restore.log}"
    RESTORE_TIMEOUT="${RESTORE_TIMEOUT:-7200}"
    EMAIL_NOTIFICATIONS="${EMAIL_NOTIFICATIONS:-false}"
    EMAIL_TO="${EMAIL_TO:-}"
    SYSLOG_TAG="${SYSLOG_TAG:-postgres-restore}"
    CONFIRMATION_REQUIRED="${CONFIRMATION_REQUIRED:-true}"

    log "DEBUG" "Environment variables loaded successfully"
    log "DEBUG" "Container name: $CONTAINER_NAME"
}

# Function to validate backup file
validate_backup_file() {
    local file="$1"

    # Check if file exists
    if [[ ! -f "$file" ]]; then
        log "ERROR" "Backup file not found: $file"
        exit 1
    fi

    # Check if file is readable
    if [[ ! -r "$file" ]]; then
        log "ERROR" "Backup file is not readable: $file"
        exit 1
    fi

    # Check if file is a gzip file
    if [[ "$file" =~ \.gz$ ]]; then
        log "DEBUG" "Validating gzip integrity"
        if ! gzip -t "$file"; then
            log "ERROR" "Backup file is corrupted or not a valid gzip file: $file"
            exit 1
        fi
    fi

    log "DEBUG" "Backup file validation passed: $file"
}

# Function to get file size in human readable format
get_file_size() {
    local file="$1"
    if [[ -f "$file" ]]; then
        du -h "$file" | cut -f1
    else
        echo "0B"
    fi
}

# Function to get backup file info
get_backup_info() {
    local file="$1"
    local size=$(get_file_size "$file")
    local date=$(stat -c %y "$file" 2>/dev/null | cut -d' ' -f1 || echo "Unknown")

    echo "File: $(basename "$file")"
    echo "Size: $size"
    echo "Date: $date"
    echo "Path: $file"
}

# Function to confirm restore operation
confirm_restore() {
    if [[ "$CONFIRMATION_REQUIRED" != "true" ]]; then
        return 0
    fi

    echo -e "${YELLOW}"
    echo "=== RESTORE CONFIRMATION ==="
    echo "WARNING: This will replace all data in the database!"
    echo ""
    echo "Target Database: $POSTGRES_DB"
    echo "Container: $CONTAINER_NAME"
    echo ""
    echo "Backup Information:"
    get_backup_info "$BACKUP_FILE"
    echo ""
    echo -e "${RED}This operation cannot be undone!${NC}"
    echo ""

    read -p "Are you sure you want to proceed? (type 'YES' to confirm): " confirmation

    if [[ "$confirmation" != "YES" ]]; then
        log "INFO" "Restore operation cancelled by user"
        exit 0
    fi

    log "INFO" "User confirmed restore operation"
}

# Function to create database backup before restore
create_pre_restore_backup() {
    log "INFO" "Creating pre-restore backup as safety measure"

    local timestamp=$(date '+%Y%m%d_%H%M%S')
    local pre_backup_file="${BACKUP_DIR}/pre_restore_backup_${timestamp}.sql.gz"

    # Create backup directory if it doesn't exist
    mkdir -p "$BACKUP_DIR"

    # Create backup
    if docker exec "$CONTAINER_NAME" pg_dump \
        -U "$POSTGRES_USER" \
        -d "$POSTGRES_DB" \
        --no-password \
        --verbose \
        --clean \
        --if-exists \
        | gzip > "$pre_backup_file"; then

        log "INFO" "Pre-restore backup created: $(basename "$pre_backup_file")"
        log "INFO" "You can use this backup to rollback if needed"
    else
        log "WARN" "Failed to create pre-restore backup, but continuing with restore"
    fi
}

# Function to test database connectivity
test_database_connection() {
    log "DEBUG" "Testing database connectivity"

    if docker exec "$CONTAINER_NAME" psql \
        -U "$POSTGRES_USER" \
        -d "$POSTGRES_DB" \
        -c "SELECT 1;" >/dev/null 2>&1; then

        log "DEBUG" "Database connection successful"
        return 0
    else
        log "ERROR" "Cannot connect to database"
        return 1
    fi
}

# Function to perform the restore
perform_restore() {
    log "INFO" "Starting PostgreSQL restore from: $(basename "$BACKUP_FILE")"
    START_TIME=$(date +%s)

    # Test database connection before restore
    if ! test_database_connection; then
        exit 1
    fi

    # Create temporary file for uncompressed data if needed
    if [[ "$BACKUP_FILE" =~ \.gz$ ]]; then
        TEMP_FILE=$(mktemp)
        log "DEBUG" "Decompressing backup file to temporary location"
        gunzip -c "$BACKUP_FILE" > "$TEMP_FILE"
        local restore_file="$TEMP_FILE"
    else
        local restore_file="$BACKUP_FILE"
    fi

    # Perform restore with timeout
    log "INFO" "Executing restore operation"
    if timeout "$RESTORE_TIMEOUT" docker exec -i "$CONTAINER_NAME" psql \
        -U "$POSTGRES_USER" \
        -d "$POSTGRES_DB" \
        --no-password \
        --quiet \
        < "$restore_file"; then

        log "INFO" "Restore completed successfully"
    else
        log "ERROR" "Restore operation failed"
        exit 1
    fi

    # Verify restore by testing connection
    if test_database_connection; then
        log "INFO" "Post-restore database connectivity test passed"
    else
        log "ERROR" "Post-restore database connectivity test failed"
        exit 1
    fi
}

# Function to get the most recent backup file
get_most_recent_backup() {
    if [[ ! -d "$BACKUP_DIR" ]]; then
        log "ERROR" "Backup directory does not exist: $BACKUP_DIR"
        return 1
    fi

    local most_recent=$(find "$BACKUP_DIR" -name "postgres_backup_*.sql.gz" -type f -printf '%T@ %p\n' | sort -n | tail -n 1 | cut -d' ' -f2-)

    if [[ -z "$most_recent" ]]; then
        log "ERROR" "No backup files found in $BACKUP_DIR"
        return 1
    fi

    echo "$most_recent"
}

# Function to show available backups
show_available_backups() {
    echo "Available backup files in $BACKUP_DIR:"
    echo ""

    if [[ -d "$BACKUP_DIR" ]]; then
        local backups=($(find "$BACKUP_DIR" -name "postgres_backup_*.sql.gz" -type f | sort -r))

        if [[ ${#backups[@]} -eq 0 ]]; then
            echo "No backup files found."
            return 1
        fi

        for i in "${!backups[@]}"; do
            local backup="${backups[$i]}"
            local size=$(get_file_size "$backup")
            local date=$(stat -c %y "$backup" 2>/dev/null | cut -d' ' -f1,2 | cut -d'.' -f1 || echo "Unknown")
            local marker=""

            # Mark the most recent backup
            if [[ $i -eq 0 ]]; then
                marker=" (most recent - default)"
            fi

            printf "%2d. %-40s %8s %s%s\n" $((i+1)) "$(basename "$backup")" "$size" "$date" "$marker"
        done

        echo ""
        echo "Use: $0 [backup_file] [container_name]"
        echo "     $0                              # Uses most recent backup"
    else
        echo "Backup directory does not exist: $BACKUP_DIR"
        return 1
    fi
}

# Function to display restore summary
show_summary() {
    local end_time=$(date +%s)
    local duration=$((end_time - START_TIME))
    local backup_size=$(get_file_size "$BACKUP_FILE")

    log "INFO" "=== RESTORE SUMMARY ==="
    log "INFO" "Container: $CONTAINER_NAME"
    log "INFO" "Database: $POSTGRES_DB"
    log "INFO" "Backup file: $(basename "$BACKUP_FILE")"
    log "INFO" "File size: $backup_size"
    log "INFO" "Duration: ${duration}s"
    log "INFO" "Status: SUCCESS"

    # Send success email
    local email_body="PostgreSQL restore completed successfully.

Container: $CONTAINER_NAME
Database: $POSTGRES_DB
Backup file: $(basename "$BACKUP_FILE")
File size: $backup_size
Duration: ${duration}s
Log file: $LOG_FILE"

    send_email "PostgreSQL Restore Successful" "$email_body"
}

# Main execution
main() {
    log "INFO" "Starting PostgreSQL restore process"
    log "DEBUG" "Script directory: $SCRIPT_DIR"
    log "DEBUG" "Backup file: $BACKUP_FILE"

    load_env  # No need to pass arguments anymore
    check_docker
    check_container
    validate_backup_file "$BACKUP_FILE"
    confirm_restore
    create_pre_restore_backup
    perform_restore
    show_summary

    log "INFO" "Restore process completed successfully"
}

# Script usage information
show_usage() {
    echo "Usage: $0 [backup_file] [container_name]"
    echo "       $0 [container_name]              # Uses most recent backup"
    echo "       $0                               # Uses most recent backup with default container"
    echo "       $0 --list"
    echo ""
    echo "Arguments:"
    echo "  backup_file       Path to the backup file to restore (optional)"
    echo "                    If not provided, uses the most recent backup"
    echo "  container_name    Name of the PostgreSQL Docker container"
    echo "                    (default: \${PROJECT_NAME}_postgres from .env file)"
    echo ""
    echo "Options:"
    echo "  --list, -l        Show available backup files"
    echo "  --help, -h        Show this help message"
    echo ""
    echo "Examples:"
    echo "  $0                                    # Restore most recent backup to default container"
    echo "  $0 mycontainer                       # Restore most recent backup to 'mycontainer'"
    echo "  $0 backup.sql.gz                     # Restore specific backup to default container"
    echo "  $0 backup.sql.gz mycontainer         # Restore specific backup to 'mycontainer'"
    echo ""
    echo "Configuration:"
    echo "  All configuration is done via the .env file including:"
    echo "  - Database connection details"
    echo "  - Confirmation requirements"
    echo "  - Email notifications"
    echo "  - Timeout settings"
    echo ""
    echo "Requirements:"
    echo "  - Docker must be running"
    echo "  - .env file must exist with all required variables"
    echo "  - Target container must be running"
    echo ""
    echo "Safety Features:"
    echo "  - Pre-restore backup is automatically created"
    echo "  - Backup file integrity verification"
    echo "  - Database connectivity testing"
    echo "  - User confirmation required (configurable)"
}

# Handle command line arguments
if [[ "${1:-}" == "--help" || "${1:-}" == "-h" ]]; then
    show_usage
    exit 0
fi

# Handle list flag
if [[ "${1:-}" == "--list" || "${1:-}" == "-l" ]]; then
    # Load env to get BACKUP_DIR
    if [[ -f "$ENV_FILE" ]]; then
        source "$ENV_FILE"
        BACKUP_DIR="${BACKUP_DIR:-${SCRIPT_DIR}/backups}"
    else
        BACKUP_DIR="${SCRIPT_DIR}/backups"
    fi
    show_available_backups
    exit 0
fi

# Parse arguments - need to handle multiple scenarios:
# 1. No args: use most recent backup, default container
# 2. One arg that's a container name: use most recent backup, specified container
# 3. One arg that's a backup file: use specified backup, default container
# 4. Two args: backup file and container name

# Load environment first to get defaults
if [[ ! -f "$ENV_FILE" ]]; then
    echo "ERROR: Environment file not found: $ENV_FILE"
    exit 1
fi
source "$ENV_FILE"

# Validate required env vars
if [[ -z "${PROJECT_NAME:-}" ]]; then
    echo "ERROR: PROJECT_NAME is required in .env file"
    exit 1
fi

# Set backup directory
BACKUP_DIR="${BACKUP_DIR:-${SCRIPT_DIR}/backups}"

# Parse arguments
if [[ $# -eq 0 ]]; then
    # No arguments - use most recent backup and default container
    BACKUP_FILE=$(get_most_recent_backup)
    if [[ $? -ne 0 ]]; then
        exit 1
    fi
    CONTAINER_NAME_ARG=""
elif [[ $# -eq 1 ]]; then
    # One argument - could be backup file or container name
    ARG1="$1"

    # Check if it's a backup file (exists or exists in backup dir)
    if [[ -f "$ARG1" ]] || [[ -f "${BACKUP_DIR}/${ARG1}" ]]; then
        # It's a backup file
        BACKUP_FILE="$ARG1"
        CONTAINER_NAME_ARG=""
    else
        # Assume it's a container name, use most recent backup
        BACKUP_FILE=$(get_most_recent_backup)
        if [[ $? -ne 0 ]]; then
            exit 1
        fi
        CONTAINER_NAME_ARG="$ARG1"
    fi
elif [[ $# -eq 2 ]]; then
    # Two arguments - backup file and container name
    BACKUP_FILE="$1"
    CONTAINER_NAME_ARG="$2"
else
    echo "ERROR: Too many arguments"
    show_usage
    exit 1
fi

# If backup file is just a filename, look for it in the backup directory
if [[ ! -f "$BACKUP_FILE" && -f "${BACKUP_DIR}/${BACKUP_FILE}" ]]; then
    BACKUP_FILE="${BACKUP_DIR}/${BACKUP_FILE}"
fi

log "INFO" "Using backup file: $(basename "$BACKUP_FILE")"

# Run main function
main