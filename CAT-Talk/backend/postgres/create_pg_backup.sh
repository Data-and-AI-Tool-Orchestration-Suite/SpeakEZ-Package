#!/bin/bash

# PostgreSQL Docker Backup Script
# Usage: ./backup_postgres.sh [container_name]
# Configuration can be modified in the CONFIG section below

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
RETENTION_COUNT=""
RETENTION_DAYS=""
MIN_DISK_SPACE_GB=""
BACKUP_TIMEOUT=""
EMAIL_NOTIFICATIONS=""
EMAIL_TO=""
SYSLOG_TAG=""

# Function to log messages with timestamp
log() {
    local level="$1"
    shift
    local message="$*"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')

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
        log "ERROR" "Backup failed with exit code $exit_code"
        send_email "PostgreSQL Backup Failed" "Backup process failed. Check logs at $LOG_FILE"
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
    if [[ ! -f "$ENV_FILE" ]]; then
        log "ERROR" "Environment file not found: $ENV_FILE"
        exit 1
    fi

    log "DEBUG" "Loading environment variables from $ENV_FILE"
    source "$ENV_FILE"

    # Validate required variables
    if [[ -z "${POSTGRES_DB:-}" || -z "${POSTGRES_USER:-}" || -z "${POSTGRES_PASSWORD:-}" || -z "${PROJECT_NAME:-}" ]]; then
        log "ERROR" "Missing required environment variables (POSTGRES_DB, POSTGRES_USER, POSTGRES_PASSWORD, PROJECT_NAME)"
        exit 1
    fi

    # Set defaults and configure from env
    POSTGRES_PORT="${POSTGRES_PORT:-5432}"
    CONTAINER_NAME="${1:-${PROJECT_NAME}_postgres}"  # Command line arg or env-based default
    BACKUP_DIR="${BACKUP_DIR:-${SCRIPT_DIR}/backups}"
    LOG_FILE="${LOG_FILE:-${BACKUP_DIR}/backup.log}"
    RETENTION_COUNT="${RETENTION_COUNT:-7}"
    RETENTION_DAYS="${RETENTION_DAYS:-30}"
    MIN_DISK_SPACE_GB="${MIN_DISK_SPACE_GB:-5}"
    BACKUP_TIMEOUT="${BACKUP_TIMEOUT:-3600}"
    EMAIL_NOTIFICATIONS="${EMAIL_NOTIFICATIONS:-false}"
    EMAIL_TO="${EMAIL_TO:-}"
    SYSLOG_TAG="${SYSLOG_TAG:-postgres-backup}"

    log "DEBUG" "Environment variables loaded successfully"
    log "DEBUG" "Container name: $CONTAINER_NAME"
}

# Function to check available disk space
check_disk_space() {
    log "DEBUG" "Checking available disk space"
    local available_space_kb=$(df "$BACKUP_DIR" | awk 'NR==2 {print $4}')
    local available_space_gb=$((available_space_kb / 1024 / 1024))

    if [[ $available_space_gb -lt $MIN_DISK_SPACE_GB ]]; then
        log "ERROR" "Insufficient disk space. Available: ${available_space_gb}GB, Required: ${MIN_DISK_SPACE_GB}GB"
        exit 1
    fi

    log "DEBUG" "Sufficient disk space available: ${available_space_gb}GB"
}

# Function to create backup directory
create_backup_dir() {
    if [[ ! -d "$BACKUP_DIR" ]]; then
        log "DEBUG" "Creating backup directory: $BACKUP_DIR"
        mkdir -p "$BACKUP_DIR"
    fi

    # Ensure log file exists
    touch "$LOG_FILE"
}

# Function to perform the backup
perform_backup() {
    local timestamp=$(date '+%Y%m%d_%H%M%S')
    BACKUP_FILE="${BACKUP_DIR}/postgres_backup_${timestamp}.sql.gz"
    TEMP_FILE="${BACKUP_FILE}.tmp"

    log "INFO" "Starting PostgreSQL backup to: $BACKUP_FILE"
    START_TIME=$(date +%s)

    # Perform backup with timeout
    timeout "$BACKUP_TIMEOUT" docker exec "$CONTAINER_NAME" pg_dump \
        -U "$POSTGRES_USER" \
        -d "$POSTGRES_DB" \
        --no-password \
        --verbose \
        --clean \
        --if-exists \
        | gzip > "$TEMP_FILE"

    # Check if backup was successful
    if [[ ${PIPESTATUS[0]} -eq 0 && ${PIPESTATUS[1]} -eq 0 ]]; then
        # Verify gzip integrity
        if gzip -t "$TEMP_FILE"; then
            mv "$TEMP_FILE" "$BACKUP_FILE"
            log "INFO" "Backup completed successfully"
        else
            log "ERROR" "Backup file integrity check failed"
            rm -f "$TEMP_FILE"
            exit 1
        fi
    else
        log "ERROR" "Backup command failed"
        rm -f "$TEMP_FILE"
        exit 1
    fi
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

# Function to clean up old backups
cleanup_old_backups() {
    log "DEBUG" "Starting backup cleanup process"

    # Count-based cleanup
    local backup_count=$(find "$BACKUP_DIR" -name "postgres_backup_*.sql.gz" -type f | wc -l)
    if [[ $backup_count -gt $RETENTION_COUNT ]]; then
        log "INFO" "Found $backup_count backups, keeping latest $RETENTION_COUNT"
        find "$BACKUP_DIR" -name "postgres_backup_*.sql.gz" -type f -printf '%T@ %p\n' | \
            sort -n | head -n -$RETENTION_COUNT | cut -d' ' -f2- | \
            while read -r old_backup; do
                log "INFO" "Removing old backup: $(basename "$old_backup")"
                rm -f "$old_backup"
            done
    fi

    # Age-based cleanup
    log "DEBUG" "Removing backups older than $RETENTION_DAYS days"
    find "$BACKUP_DIR" -name "postgres_backup_*.sql.gz" -type f -mtime +$RETENTION_DAYS -delete

    log "DEBUG" "Backup cleanup completed"
}

# Function to display backup summary
show_summary() {
    local end_time=$(date +%s)
    local duration=$((end_time - START_TIME))
    local backup_size=$(get_file_size "$BACKUP_FILE")

    log "INFO" "=== BACKUP SUMMARY ==="
    log "INFO" "Container: $CONTAINER_NAME"
    log "INFO" "Database: $POSTGRES_DB"
    log "INFO" "Backup file: $(basename "$BACKUP_FILE")"
    log "INFO" "File size: $backup_size"
    log "INFO" "Duration: ${duration}s"
    log "INFO" "Status: SUCCESS"

    # Send success email
    local email_body="PostgreSQL backup completed successfully.

Container: $CONTAINER_NAME
Database: $POSTGRES_DB
Backup file: $(basename "$BACKUP_FILE")
File size: $backup_size
Duration: ${duration}s
Log file: $LOG_FILE"

    send_email "PostgreSQL Backup Successful" "$email_body"
}

# Main execution
main() {
    log "INFO" "Starting PostgreSQL backup process"
    log "DEBUG" "Script directory: $SCRIPT_DIR"

    load_env "$@"  # Pass arguments to load_env for container name processing
    create_backup_dir
    check_docker
    check_container
    check_disk_space
    perform_backup
    cleanup_old_backups
    show_summary

    log "INFO" "Backup process completed successfully"
}

# Script usage information
show_usage() {
    echo "Usage: $0 [container_name]"
    echo ""
    echo "Arguments:"
    echo "  container_name    Name of the PostgreSQL Docker container"
    echo "                    (default: \${PROJECT_NAME}_postgres from .env file)"
    echo ""
    echo "Configuration:"
    echo "  All configuration is done via the .env file including:"
    echo "  - Database connection details"
    echo "  - Backup directory location"
    echo "  - Retention policies"
    echo "  - Disk space requirements"
    echo "  - Email notifications"
    echo "  - Timeout settings"
    echo ""
    echo "Requirements:"
    echo "  - Docker must be running"
    echo "  - .env file must exist with all required variables"
    echo "  - Sufficient disk space for backups"
}

# Handle help flag
if [[ "${1:-}" == "--help" || "${1:-}" == "-h" ]]; then
    show_usage
    exit 0
fi

# Run main function
main "$@"