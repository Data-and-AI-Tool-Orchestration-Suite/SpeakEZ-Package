cd /workspace

export UUID=$(uuidgen)
export WORKING_DIR=/tmp/working_dir/$UUID
export OUTPUT_DIR=$WORKING_DIR/output
export HOME=$WORKING_DIR
export CLEARML_CONFIG_FILE="/workspace/clearml.conf"
export CLEARML_CACHE_DIR=$WORKING_DIR/.clearml # this might be the only import clearml env var
export NVIDIA_DRIVER_CAPABILITIES=compute,utility
export LD_LIBRARY_PATH=$(python3 -c 'import os; import nvidia.cublas.lib; import nvidia.cudnn.lib; print(os.path.dirname(nvidia.cublas.lib.__file__) + ":" + os.path.dirname(nvidia.cudnn.lib.__file__))')
export LD_PRELOAD=/usr/lib/x86_64-linux-gnu/libmkl_intel_lp64.so:/usr/lib/x86_64-linux-gnu/libmkl_sequential.so:/usr/lib/x86_64-linux-gnu/libmkl_core.so:/usr/lib/x86_64-linux-gnu/libstdc++.so.6
export OMP_NUM_THREADS=1

# Where models are cached
export TORCH_HOME=/tmp/working_dir/cache/torch
export TRANSFORMERS_CACHE=/tmp/working_dir/cache/huggingface/transformers
export HF_HOME=/tmp/working_dir/cache/huggingface
export SPEECHBRAIN_CACHE=/tmp/working_dir/cache/speechbrain

mkdir -p $WORKING_DIR
mkdir -p $OUTPUT_DIR

/usr/bin/python3 /workspace/training-wrappers/verbatimizer_clearml_wrapper.py --noDelete --job_id asdf --task_name verbatimizer_template_v4

rm -rf $WORKING_DIR
