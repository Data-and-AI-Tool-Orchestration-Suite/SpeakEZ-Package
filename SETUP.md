# SpeakEZ — Setup Guide

Welcome! This guide explains how to set up the SpeakEZ oral-history
transcription website from scratch. Every step says what to type, what you should
see, and how to tell whether it worked.

If you get stuck, see **Section 12 — When things go wrong**.

---

## 1. What you are setting up

SpeakEZ is a website where archivists can upload oral-history interview
recordings and have them transcribed, summarized, and reviewed
automatically. It is made of three separate programs that work together:

| Folder | What it is | In plain words |
|---|---|---|
| **CAT-Talk** | The website | The part people see in their browser: log in, upload recordings, read transcripts |
| **oral-transcription** | The job coordinator | The part that accepts "please transcribe this file" requests and hands them to the workers |
| **cat-talk-jobs** | The workers | The part that does the heavy lifting (the actual transcription and analysis) |

All three are run using **Docker** — a program that packages software with
everything it needs so you don't have to install dozens of separate
components by hand. You will mostly be copying files, filling in settings,
and running short commands.

### The recommended layout

This guide assumes the simplest reliable setup:

- **One "web server"** (any computer running Linux — a university VM is
  fine, no graphics card needed). It runs the website, the job coordinator,
  and the file storage.
- **One "worker machine"** (a computer with an NVIDIA graphics card / GPU).
  It does the transcription work. (You can also run worker tasks on the
  web server if it has a GPU; and analysis-only jobs need no GPU at all.)

Throughout this guide we use these example values — replace them with your
own wherever you see them:

| Example value | What it is |
|---|---|
| `192.168.1.50` | The web server's IP address (find yours with `ip addr` — look for something like `inet 192.168.x.x`) |
| `https://speakez.example.org` | The website's public address |
| `daboyd2@uky.edu` | The first administrator's email (UKY linkblue style) |

### How work flows through the system

1. A user logs into the website and uploads a recording.
2. The website asks the job coordinator to start a job.
3. The job coordinator queues the job on a **ClearML** server — a free
   coordination service that tracks jobs.
4. The worker machine is constantly checking that queue. When it sees a job
   it can do, it takes it, downloads the recording from storage, processes
   it, and uploads the results.
5. Progress updates appear back on the website automatically.

```
  Browser ──► Website (CAT-Talk) ──► Job coordinator (oral-transcription)
                    ▲                           │
                    │ progress updates          │ queues jobs on
                    │                           ▼
              Worker machine (cat-talk-jobs) ◄── ClearML queue
                    │
                    ▼
          File storage (MinIO) + AI service (LLM)
```

### The two shared passwords you must understand

The three programs check each other's identity using two secret passwords
(called **API keys**). You will make these up yourself — two long random
sentences are perfect.

- **Key A** — the "start a job" password. The website and the workers use it
  to talk to the job coordinator.
- **Key B** — the "report progress" password. The workers and the job
  coordinator use it to report back to the website.

Each key is stored in **three places** (Section 3 collects them in one
table). If jobs never start or never update, a mismatched key is the first
thing to check.

---

## 2. Before you begin — checklist

You need:

- [ ] **Web server**: a Linux computer with at least 4 GB of memory and
      40 GB of free disk, that users can reach on the network.
- [ ] **Worker machine**: a computer with an NVIDIA GPU (8 GB+ video memory
      recommended), 100 GB free disk, and the NVIDIA drivers installed
      (type `nvidia-smi` — you should see a table with your GPU).
- [ ] **Docker** installed on both (Section 3).
- [ ] **A ClearML server to use**: either the free cloud service, or your
      own server. This is its own step-by-step process — see the separate
      **`CLEARML-SETUP.md`** guide that ships with this package. At minimum
      you will need an **access key** and **secret key** from it.
- [ ] **A HuggingFace account**: sign up at <https://huggingface.co> (free).
      Create a "read" **access token** (Settings → Access Tokens). Then visit
      each of these three pages and click *"Agree and access repository"*:
      [speaker-diarization-3.1](https://huggingface.co/pyannote/speaker-diarization-3.1),
      [segmentation-3.0](https://huggingface.co/pyannote/segmentation-3.0),
      [wespeaker-voxceleb-resnet34-LM](https://huggingface.co/pyannote/wespeaker-voxceleb-resnet34-LM).
      These are the speech models; the approval is automatic but required.
- [ ] **A CiLogon application** (for website login): register at
      <https://cilogon.org/oauth2/register>. For "Application URL" enter your
      site address (e.g. `https://speakez.example.org`). Save the
      **Client ID** and **Client Secret** it gives you.
- [ ] **An AI (LLM) service** for the analysis features: an
      "OpenAI-compatible" service — ask your IT/AI provider for the service
      **address** (ends in `/v1`) and an **API key**. (The analysis jobs —
      risk review, summaries, OHMS description — use it; plain transcription
      does not.)
- [ ] **Your two made-up keys** (Key A and Key B from Section 1) — write
      them down.

Gather everything into this table as you go — every setting in this guide
comes from it:

| Setting | Your value |
|---|---|
| Web server IP | |
| Website public address | |
| Admin email (first login) | |
| CiLogon client ID / secret | |
| ClearML access key / secret key | |
| HuggingFace token (starts `hf_`) | |
| LLM service address / API key | |
| Key A ("start a job") | |
| Key B ("report progress") | |
| MinIO storage username / password | (you create these in Section 5) |

---

## 3. Install Docker

Docker is available for Linux servers, and as "Docker Desktop" for Windows
and Mac. On the server, the quickest route is Docker's official install
script. Open a **terminal** (the black window where you type commands) and
run, one line at a time:

```bash
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh
sudo usermod -aG docker $USER
```

Now **log out and log back in** (this applies the last line), then check it
worked:

```bash
docker --version
docker compose version
```

✔ **Checkpoint:** both commands print version numbers (e.g.
`Docker version 27.x.x`, `Docker Compose version v2.x.x`).

On the **worker machine**, do the same, plus the NVIDIA GPU support:

```bash
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh
sudo usermod -aG docker $USER
# GPU support for Docker:
sudo apt-get install -y nvidia-container-toolkit
sudo nvidia-ctk runtime configure --runtime=docker
sudo systemctl restart docker
```

Log out and back in, then confirm `docker --version` works. (The
`nvidia-container-toolkit` line needs Ubuntu/Debian; on other systems see
Docker's documentation for "NVIDIA Container Toolkit".)

---

## 4. Get the code onto the machines

1. On the GitHub page for this package, click the green **`<> Code`**
   button → **Download ZIP**.
2. Copy the ZIP to the web server (e.g. with a USB stick, or `scp` — ask IT
   if unsure) and uncompress it. You should now have a folder containing
   `CAT-Talk/`, `oral-transcription/`, `cat-talk-jobs/`,
   `example-configs/`, this guide, and `CLEARML-SETUP.md` (the separate
   guide for the job-coordination server, from Section 2's checklist).
3. Put a copy of the folder on the worker machine as well (only the
   `cat-talk-jobs/` folder is strictly needed there, but the whole ZIP is
   simplest).

---

## 5. Set up the file storage (MinIO) — on the web server

Recordings and finished transcripts are stored in an "S3-compatible"
storage service. **MinIO** is a free one that runs in a single Docker
container.

1. On the web server, make a folder for it and create its settings file:

   ```bash
   mkdir -p ~/speakez-storage && cd ~/speakez-storage
   nano docker-compose.yml
   ```

   (`nano` is a simple text editor: type, then press **Ctrl+O** and
   **Enter** to save, **Ctrl+X** to exit.)

2. Paste this in, choose your own username/password, and save:

   ```yaml
   services:
     minio:
       # (MinIO's images moved from Docker Hub to quay.io in 2025 —
       #  "minio/minio" on Docker Hub no longer exists)
       image: quay.io/minio/minio
       command: server /data --console-address ":9001"
       ports:
         - "9000:9000"
         - "9001:9001"
       volumes:
         - ./data:/data
       environment:
         MINIO_ROOT_USER: CHANGE-ME-minio-user
         MINIO_ROOT_PASSWORD: CHANGE-ME-minio-password
       restart: unless-stopped
   ```

3. Start it and create the two storage "buckets" (think: folders).
   Run this command, replacing the username/password with the ones you
   picked above:

   ```bash
   docker compose up -d
   docker run --rm --network host --entrypoint /bin/sh quay.io/minio/mc -c \
     "mc alias set local http://localhost:9000 CHANGE-ME-minio-user CHANGE-ME-minio-password && \
      mc mb local/speakez-audio local/speakez-output"
   ```

   It prints `Added 'local' successfully` and two `Bucket created
   successfully` lines. (If it complains about connecting, wait a few
   seconds and try again — the storage service may still be starting.)

✔ **Checkpoint:** open `http://192.168.1.50:9001` in a browser, sign in with
the MinIO username/password, and you should see two buckets:
`speakez-audio` and `speakez-output`.

**Write down** the MinIO username and password — several config files need
them.

---

## 6. The website (CAT-Talk) — on the web server

### 6.1 Copy the example settings into place

In the package folder:

```bash
cd CAT-Talk
cp ../example-configs/CAT-Talk.env .env
cp ../example-configs/CAT-Talk.config.php frontend/config.php
cp backend/postgres/init.sql.example backend/postgres/init.sql
```

### 6.2 Edit the files

**File 1 — `.env`** (the website's basic settings). Open it with
`nano .env` and change:

| Setting | Change to |
|---|---|
| `POSTGRES_PASSWORD` | any long random phrase (you'll type it twice more) |
| `ADMIN_EPPN` | the first admin's email, e.g. `daboyd2@uky.edu` |
| `MC_URL`, `MC_USER`, `MC_PASSWORD` | the storage address and the MinIO username/password from Section 5 — the address is `http://192.168.1.50:9000` with **your** server's IP. These are read when the website is **built** (next step), so the storage service must be running first |

**File 2 — `frontend/config.php`** (the website's full configuration).
Open it with `nano frontend/config.php` and change **every value that
contains `CHANGE-ME`**:

| Setting | Change to |
|---|---|
| `pass` (database) | the same password as in `.env` |
| `clientId`, `clientSecret` (CiLogon) | from your CiLogon registration |
| `redirectUri` | your site address + `/callback` |
| `id`, `secret` (MinIO) | the MinIO username/password from Section 5 |
| `endpoint` | `http://192.168.1.50:9000` (**your server's IP**, not localhost) |
| `backend_server` | `http://192.168.1.50:5050` (**your server's IP**) |
| `apiKey` | **Key A** |
| `api_key` | **Key B** |

You may also want to set `title_text` to your project's name.

**File 3 — `backend/postgres/init.sql`** (the database's starting setup).
**Nothing to change** — when the website is built (next step), the admin
email from `ADMIN_EPPN` in `.env` is inserted into it automatically.

### 6.3 Start the website

The storage service from Section 5 must be running before this step —
the very first start builds the website's PHP container, and that build
connects to the storage once to save its settings:

```bash
docker compose up -d
```

The first start builds and initializes for a minute or two.

Then install the website's extras with **Composer** (a PHP dependency
tool) — a one-time step, run inside the running PHP container:

```bash
docker ps
```

Find the PHP container in the list (its name ends in `_php`) and note the
first four characters of its ID — for example `a1b2`. Then open a shell
inside it and install:

```bash
docker exec -it a1b2 /bin/sh
composer install
exit
```

(If `composer install` reports an error, try `composer update` instead.)

✔ **Checkpoint:** open `http://192.168.1.50:8080` in a browser → you should
see the SpeakEZ login page, and logging in with the admin account puts you
into the site. (The very first page load can take ~30 seconds while the
database initializes.)

### 6.4 Turn on the plugins

Log in as the admin → open the **Plugins** page → activate **S3**
(required — manages file storage), **Projects**, and **API Keys**.

> **Note on addresses:** for a real deployment your IT staff will put the
> site on a proper HTTPS address (like `https://speakez.example.org`) and
> the CiLogon `redirectUri` must match it exactly. Until then, testing on
> `http://IP:8080` is fine — just keep every config consistent with
> whichever address you settle on.

---

## 7. The job coordinator (oral-transcription) — on the web server

### 7.1 Copy the example settings into place

```bash
cd ../oral-transcription
cp ../example-configs/oral-transcription.config.ini config.ini
cp ../example-configs/oral-transcription.clearml.conf clearml.conf
cp ../example-configs/oral-transcription.env .env
```

### 7.2 Edit the three files

**`config.ini`** — change every `CHANGE-ME`:

| Setting | Change to |
|---|---|
| `api_key_hash` | the "fingerprint" of **Key A** — see the box below |
| `callback_url` | your website's public address |
| `api_key` | **Key B** |
| `s3_user`, `s3_password` | MinIO username/password |
| `s3_url` and `s3_url_no_path` | `http://192.168.1.50:9000` (**server IP**) |
| `openai_api_key`, `openai_api_base` | your AI service key and address |

> **The Key A fingerprint box.** The coordinator doesn't store Key A
> itself — it stores the MD5 "fingerprint" of it. After you pick Key A,
> get its fingerprint by running this on any computer with Python,
> replacing `YOUR-KEY-A` with your actual Key A:
>
> ```bash
> python -c "import hashlib; print(hashlib.md5(b'YOUR-KEY-A').hexdigest())"
> ```
>
> Paste the 32-character result into `api_key_hash`. (The example files
> ship with a matching pair for the example key
> `SpeakEZ-Job-Key-CHANGE-ME-0001`, fingerprint
> `13259adddbc8f5096ff62f551476d8c1` — if you keep that key as-is, keep the
> fingerprint as-is too.)

**`clearml.conf`** — paste your ClearML access/secret keys, and put the
MinIO username/password and server IP in the two `credentials` blocks (the
`host` there is the IP **without** `http://`).

**`.env`** — paste your HuggingFace token (`hf_...`).

### 7.3 Start it

```bash
docker compose up -d --build
```

This builds for several minutes the first time.

✔ **Checkpoint:** in a browser, open
`http://192.168.1.50:5050/get-queued-jobs`... you'll see an error page —
that's expected (this endpoint needs a key). Better check, in the terminal:

```bash
curl -s -H "apiKey: YOUR-KEY-A" http://localhost:5050/get-queued-jobs
```

✔ It should print `[]` (an empty list — the queue is empty). If it prints
something about "Unauthorized", Key A or its fingerprint is wrong.

---

## 8. The workers (cat-talk-jobs) — on the worker machine

### 8.1 Copy the example settings into place

```bash
cd cat-talk-jobs
cp ../example-configs/cat-talk-jobs.env .env
cp ../example-configs/cat-talk-jobs.config.ini config.ini
cp ../example-configs/cat-talk-jobs.clearml.conf clearml.conf
```

### 8.2 Edit the three files

**`.env`**:

| Setting | Change to |
|---|---|
| `USE_GPU` | `1` on the GPU machine |
| `HF_TOKEN` | your HuggingFace token |

**`config.ini`** — change every `CHANGE-ME`:

| Setting | Change to |
|---|---|
| `api_key` | **Key A** |
| `updates_api_key` | **Key B** |
| `callback_url` and `callback_url_2` | your website's public address (both the same) |
| `s3_address` | your server IP (`192.168.1.50`, no `http://`) |
| `openai_api_key`, `openai_api_base` | your AI service key and address |
| `large_model` | the model name your AI provider recommends |
| `job_types` | which jobs this machine runs — see below |
| `auth_token` | your HuggingFace token |

> **Which `job_types`?** Transcription jobs (`verbatimizer`,
> `synchronizer`) need the GPU — run them on the GPU machine. Analysis jobs
> (`custom`, `riskalyzer`, `describalizer`, `ohmsifier`) only call the AI
> service — they can run on any machine with `USE_GPU=0`. If you have one
> GPU machine doing everything, list all six.

**`clearml.conf`** — the **same values** as the coordinator's
`clearml.conf` (ClearML keys, MinIO credentials, server IP).

### 8.3 Start the worker

On the GPU machine:

```bash
docker compose -f docker-compose.yml -f docker-compose.gpu.yml up -d --build
```

(The second `-f` file is what gives the worker access to the GPU. On a
machine without a GPU, just `docker compose up -d --build`.)

This build takes a while (it downloads the transcription software).

✔ **Checkpoint:**

```bash
docker compose logs -f app
```

You should see `No jobs in queue` printed every 30 seconds. Press **Ctrl+C**
to stop watching. If instead you see errors about ClearML, the ClearML keys
in `clearml.conf` are wrong.

### 8.4 One-time setup: introduce the job types to ClearML

The coordinator creates jobs by copying five pre-made "template" jobs. The
following commands register those templates on the ClearML server (run from
the `cat-talk-jobs` folder):

```bash
docker compose exec app bash /workspace/create-templates/cat-talk-verbatimizer.sh
docker compose exec app bash /workspace/create-templates/cat-talk-riskalyzer.sh
docker compose exec app bash /workspace/create-templates/cat-talk-ohmsifier.sh
docker compose exec app bash /workspace/create-templates/cat-talk-synchronifier.sh
docker compose exec app bash /workspace/create-templates/cat-talk-custom.sh
```

Each one runs for a bit and **ends with an error about job "asdf" — that is
normal**. The template gets saved before the error; the "job" was never
real.

Then, in the ClearML website (app.clearml.ai), find your project and
confirm these five task names exist (create the project first if asked):
`verbatimizer_template_v4`, `riskalyzer_template_v2`,
`ohmsifier_template_v0`, `synchronifier_template_v1`,
`custom_job_template_v1`. Also make sure a **queue** named `speakez` exists
(ClearML → Projects → your project → Queues).

> **Important:** the template names above must match the `[ClearML]`
> section of the coordinator's `config.ini` (`project_name` and
> `queue_name`). The examples use project `SpeakEZ` and queue `speakez`.

---

## 9. Test everything end to end

1. Open the website and log in.
2. Upload a short recording (1–2 minutes is plenty).
3. Start a **riskalyzer** job on it — it's AI-only, so it works even
   without a GPU worker, and it's the fastest full test of the plumbing.
4. Watch it move through the system:
   - the job's status changes on the website on its own, and
   - a task appears in the ClearML website and then runs, and
   - `docker compose logs -f app` on the worker shows activity.
5. If you have a GPU worker, also start a **verbatimizer** job — this tests
   the actual transcription and the HuggingFace access.
6. Confirm results appear on the website and files land in the
   `speakez-output` bucket (visible at `http://192.168.1.50:9001`).

---

## 10. Everyday operation

**Where things live** (run these from inside each program's folder):

| Action | Command |
|---|---|
| Start | `docker compose up -d` |
| Stop | `docker compose down` |
| Restart | `docker compose restart` |
| See logs | `docker compose logs -f` (Ctrl+C to stop) |

**Backups.** The website's database is the critical thing to back up. From
the `CAT-Talk` folder:

```bash
bash backend/postgres/create_pg_backup.sh
```

Copy the backup file it produces somewhere safe (another machine, cloud
storage). Restore with `restore_pg_backup.sh` when needed. The recordings
themselves live in the MinIO folder (`~/speakez-storage/data` on the web
server) — back that folder up too.

**Adding another worker machine:** copy `cat-talk-jobs` to it, give it the
same config files (with its own `job_types` if you want to split work),
and start it. Workers coordinate themselves through ClearML.

**Going live (HTTPS):** before real users arrive, ask IT to put the site
behind a proper HTTPS address, then update the address in: the website's
`config.php` (`redirectUri`), and both `config.ini` files
(`callback_url` / `callback_url_2`), and switch `environment` in
`config.php` to `"production"`.

---

## 11. Where every secret lives (reference)

| Secret | Website (`config.php`) | Coordinator (`config.ini`) | Workers (`config.ini`) |
|---|---|---|---|
| **Key A** (start jobs) | `apiKey` | → stored as fingerprint in `api_key_hash` | `api_key` |
| **Key B** (report progress) | `api_key` | `api_key` | `updates_api_key` |
| Database password | `pass` + `.env` | — | — |
| MinIO username/password | `id` / `secret` | `[S3 Server]` + `clearml.conf` | `clearml.conf` |
| ClearML keys | — | `clearml.conf` | `clearml.conf` |
| HuggingFace token | — | `.env` (`HF_TOKEN`) | `.env` + `auth_token` |
| AI service key | — | `[LLM]` | `[LLM]` |
| CiLogon login keys | `clientId` / `clientSecret` | — | — |

---

## 12. When things go wrong

| What you see | What it usually means | What to do |
|---|---|---|
| Website shows an error page immediately | `.env` or `config.php` has a typo, or the database isn't up | `docker compose logs php` and `docker compose logs postgres` — look for the word "error"; check passwords match between the two files |
| Login fails or bounces back with an error | CiLogon settings wrong | The `redirectUri` in `config.php` must exactly match your site address + `/callback`, and must be registered in CiLogon |
| Login shows a wall of `Deprecated: ... AbstractProvider` / "headers already sent" text | An old copy of the vendor libraries (installed before this fix) | Delete the `frontend/vendor` folder, then re-run the `composer install`/`composer update` step (Section 6.3). This package ships the corrected library version (`league/oauth2-client` 2.9.1) and a PHP setting (`prod.ini`) that keeps notices out of page output |
| "Unauthorized" when testing the coordinator (Section 7.3) | Key A mismatch | Compare `apiKey` (website), `api_key` (workers) and the `api_key_hash` fingerprint (coordinator) |
| Upload works but starting a job does nothing | The website can't reach the coordinator | Check `backend_server` in the website's `config.php` uses the **server IP** (not `localhost`) and that `http://IP:5050/get-queued-jobs` responds from the server |
| Job starts but status never changes | Key B mismatch, or wrong `callback_url` | Check Key B in all three places (Section 11) and that `callback_url`(s) point at the website's public address |
| Jobs sit "queued" forever | No worker is picking them up | On the worker: `docker compose logs app` — check for ClearML errors; confirm the worker's `job_types` includes that job; confirm the ClearML queue name matches |
| `clearml-agent` errors about a missing task | Templates weren't registered | Re-run the Section 8.4 commands; confirm project/queue names match the coordinator's `config.ini` |
| Transcription job fails with a HuggingFace/401 error | Token missing or model access not granted | Re-do the HuggingFace checklist in Section 2 (token + all three "Agree" clicks); confirm the token is in the worker's `.env` **and** `config.ini` |
| `docker: permission denied` | Your user isn't in the docker group | Run `sudo usermod -aG docker $USER`, log out, log back in |
| GPU not visible to the worker | Toolkit/drivers | `nvidia-smi` must work on the machine itself; then re-run the Section 3 GPU lines and start with **both** `-f` flags |
| The website build fails at `mc alias set` | The storage service isn't running (or the address/username/password in `.env` don't match it) | Start Section 5's storage first; check `MC_URL` uses the **server's IP**, not `localhost`; then `docker compose up -d --build` again |
| `pull access denied for minio/...` or "repository does not exist" | Old instructions using Docker Hub for MinIO | MinIO's images moved to quay.io in 2025 — use `quay.io/minio/minio` and `quay.io/minio/mc` as in Section 5 |

**Still stuck?** The original deployment was maintained by Vaiden Logan
(<vaiden.logan@uky.edu>).

---

## 13. Appendix — notes on this package

- Each of the three programs is included as a plain folder with a single
  "Initial commit" of git history; no development history is included.
- **Secrets were removed** before packaging (a HuggingFace token that was
  hard-coded in two files now comes from the `HF_TOKEN` setting; some
  commented-out storage credentials in an example file were blanked).
- **Example configs were corrected** to match what the programs actually
  read: the job-type model name (`[Model]`), two missing storage/AI
  settings in the coordinator's example, and the three job-connection
  settings (`backend_server`, `apiKey`, `api_key`) in the website's
  example.
- The `example-configs/` folder in this package contains every settings
  file used in this guide, pre-filled with the example values from
  Section 1.
