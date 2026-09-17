#!/bin/bash

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Function to print colored output
print_info() { echo -e "${BLUE}[INFO]${NC} $1"; }
print_success() { echo -e "${GREEN}[SUCCESS]${NC} $1"; }
print_warning() { echo -e "${YELLOW}[WARNING]${NC} $1"; }
print_error() { echo -e "${RED}[ERROR]${NC} $1"; }

# Parse command line arguments
AUTO_MERGE=false
FORCE_PUSH=false

while [[ $# -gt 0 ]]; do
  case $1 in
    --auto-merge|-a)
      AUTO_MERGE=true
      shift
      ;;
    --force-push|-f)
      FORCE_PUSH=true
      shift
      ;;
    --help|-h)
      echo "Usage: $0 [OPTIONS]"
      echo "Options:"
      echo "  --auto-merge, -a    Automatically merge upstream changes if behind"
      echo "  --force-push, -f    Force push after merging (use with caution)"
      echo "  --help, -h          Show this help message"
      exit 0
      ;;
    *)
      print_error "Unknown option: $1"
      exit 1
      ;;
  esac
done

# Check if we're in a git repository
if ! git rev-parse --is-inside-work-tree &> /dev/null; then
    print_error "Not in a git repository"
    exit 1
fi

# Load environment variables from .env file
if [[ -f .env ]]; then
    print_info "Loading environment variables from .env"
    export $(grep -v '^#' .env | xargs)
else
    print_warning "No .env file found"
fi

# Check if TEMPLATE_REPO_URL is set
if [[ -z "$TEMPLATE_REPO_URL" ]]; then
    print_error "TEMPLATE_REPO_URL not set in environment or .env file"
    print_info "Please add TEMPLATE_REPO_URL=<your-template-repo-url> to your .env file"
    exit 1
fi

print_info "Template repository: $TEMPLATE_REPO_URL"

# Check if upstream remote exists
if git remote get-url upstream &> /dev/null; then
    print_info "Upstream remote already exists"
    CURRENT_UPSTREAM=$(git remote get-url upstream)
    
    # Check if upstream URL matches the one in .env
    if [[ "$CURRENT_UPSTREAM" != "$TEMPLATE_REPO_URL" ]]; then
        print_warning "Upstream URL ($CURRENT_UPSTREAM) doesn't match TEMPLATE_REPO_URL ($TEMPLATE_REPO_URL)"
        print_info "Updating upstream remote URL"
        git remote set-url upstream "$TEMPLATE_REPO_URL"
        print_success "Updated upstream remote URL"
    fi
else
    print_info "Setting upstream remote to $TEMPLATE_REPO_URL"
    git remote add upstream "$TEMPLATE_REPO_URL"
    print_success "Added upstream remote"
fi

# Fetch upstream changes
print_info "Fetching upstream changes..."
if ! git fetch upstream; then
    print_error "Failed to fetch from upstream"
    exit 1
fi
print_success "Fetched upstream changes"

# Get current branch
CURRENT_BRANCH=$(git branch --show-current)
print_info "Current branch: $CURRENT_BRANCH"

# Check if current branch exists on upstream (fallback to main/master)
UPSTREAM_BRANCH="main"
if git show-ref --verify --quiet refs/remotes/upstream/main; then
    UPSTREAM_BRANCH="main"
elif git show-ref --verify --quiet refs/remotes/upstream/master; then
    UPSTREAM_BRANCH="master"
else
    print_error "Neither 'main' nor 'master' branch found on upstream"
    exit 1
fi

print_info "Using upstream branch: $UPSTREAM_BRANCH"

# Check if local branch is behind upstream
LOCAL_COMMIT=$(git rev-parse HEAD)
UPSTREAM_COMMIT=$(git rev-parse upstream/$UPSTREAM_BRANCH)

if [[ "$LOCAL_COMMIT" == "$UPSTREAM_COMMIT" ]]; then
    print_success "Repository is up to date with upstream"
    exit 0
fi

# Check if we can fast-forward
MERGE_BASE=$(git merge-base HEAD upstream/$UPSTREAM_BRANCH)

if [[ "$MERGE_BASE" == "$LOCAL_COMMIT" ]]; then
    print_info "Repository is behind upstream (can fast-forward)"
    BEHIND_COUNT=$(git rev-list --count HEAD..upstream/$UPSTREAM_BRANCH)
    print_warning "Your repository is $BEHIND_COUNT commits behind the template"
elif [[ "$MERGE_BASE" == "$UPSTREAM_COMMIT" ]]; then
    print_info "Repository is ahead of upstream"
    AHEAD_COUNT=$(git rev-list --count upstream/$UPSTREAM_BRANCH..HEAD)
    print_success "Your repository is $AHEAD_COUNT commits ahead of the template"
    exit 0
else
    print_warning "Repository has diverged from upstream"
    BEHIND_COUNT=$(git rev-list --count HEAD..upstream/$UPSTREAM_BRANCH)
    AHEAD_COUNT=$(git rev-list --count upstream/$UPSTREAM_BRANCH..HEAD)
    print_warning "Your repository is $BEHIND_COUNT commits behind and $AHEAD_COUNT commits ahead of the template"
fi

# Show what commits we're behind
if [[ $BEHIND_COUNT -gt 0 ]]; then
    print_info "Recent commits in template:"
    git log --oneline --max-count=5 HEAD..upstream/$UPSTREAM_BRANCH
    echo
fi

# If auto-merge is not enabled, just inform and exit
if [[ "$AUTO_MERGE" != "true" ]]; then
    print_info "To merge upstream changes, run with --auto-merge flag"
    print_info "Example: $0 --auto-merge"
    exit 0
fi

# Ensure working directory is clean before merging
if ! git diff-index --quiet HEAD --; then
    print_error "Working directory is not clean. Please commit or stash changes before merging"
    git status --porcelain
    exit 1
fi

print_info "Starting merge of upstream/$UPSTREAM_BRANCH into $CURRENT_BRANCH"

# Attempt to merge upstream
if git merge upstream/$UPSTREAM_BRANCH --no-edit; then
    print_success "Successfully merged upstream changes"
    
    # Ask if user wants to push
    if [[ "$FORCE_PUSH" == "true" ]]; then
        print_info "Force pushing changes..."
        git push --force-with-lease
        print_success "Force pushed changes to origin"
    else
        echo
        read -p "Do you want to push the changes? (y/N): " -n 1 -r
        echo
        if [[ $REPLY =~ ^[Yy]$ ]]; then
            if git push; then
                print_success "Pushed changes to origin"
            else
                print_warning "Push failed. You may need to use --force-push if the history was rewritten"
            fi
        fi
    fi
else
    print_warning "Merge conflicts detected"
    print_info "Please resolve conflicts manually:"
    echo
    git status
    echo
    print_info "After resolving conflicts, run:"
    print_info "  git add ."
    print_info "  git commit"
    print_info "  git push"
    exit 1
fi