#!/bin/bash
echo "------- Decrementing days until archive --------"
psql -U postgres -d speakez -c "UPDATE public.files SET archived = (archived::jsonb || jsonb_build_object('days_left', ((archived->>'days_left')::int - 1)::text))::json WHERE (archived->>'days_left')::int > 0;"
echo "------- Checking for Auto-archives --------"
psql -U postgres -d speakez -c "UPDATE public.files SET archived = '{\"archived\": true, \"days_left\": 0}' WHERE (archived->>'days_left')::int = 0 AND (archived->>'archived')::bool = false;"