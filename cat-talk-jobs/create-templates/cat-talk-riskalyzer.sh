cd /workspace

export UUID=$(uuidgen)
export WORKING_DIR=/tmp/working_dir/$UUID
export OUTPUT_DIR=$WORKING_DIR/output
export HOME=$WORKING_DIR
export CLEARML_CONFIG_FILE="/workspace/clearml.conf"
export CLEARML_CACHE_DIR=$WORKING_DIR/.clearml # this might be the only import clearml env var
mkdir -p $WORKING_DIR
mkdir -p $OUTPUT_DIR

/usr/bin/python3 /workspace/training-wrappers/riskalyzer_clearml_wrapper.py --job_id asdf --task_name riskalyzer_template_v2

rm -rf $WORKING_DIR
