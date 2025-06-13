<?php
/*
Large File Transfer Web App Design (Detailed)

1. Initialization:
   - On first run, the app creates a 'tmp' directory if it does not exist.
   - The app prompts the user for an initial password and saves it as '.password' in the 'tmp' directory (hashed).
   - All further access requires password authentication (stored in session).

2. Sender Flow:
   - The sender selects a file and clicks "Start Sending".
   - The frontend requests a new session ID from the backend via the 'create_session' API endpoint.
   - The file is split into chunks in the browser using JavaScript (chunk size is set in the PHP config, default 5MB).
   - For each chunk:
     a. The frontend asks the backend if it is ready to receive the next chunk via the 'ready' API endpoint.
        - The backend checks if the 'tmp' directory is under the configured size limit (default 100MB) and if there is enough disk space.
        - If not ready, the sender is notified and upload stops.
     b. If ready, the chunk is uploaded to the backend via the 'upload_chunk' API endpoint (multipart/form-data).
        - The backend saves each chunk as 'tmp/<session_id>/chunk_<index>'.
        - On the first chunk, a 'meta.json' file is created in the session directory with file name and total chunk count.
   - After all chunks are uploaded, the sender is shown the session ID to share with the receiver.

   API endpoints for sender:
     - ?mode=api&action=create_session      (POST/GET) → {ok, session_id}
     - ?mode=api&action=ready              (GET)      → {ok} or {ok: false, reason}
     - ?mode=api&action=upload_chunk       (POST, multipart) with session_id, chunk_index, total_chunks, file_name, chunk

3. Receiver Flow:
   - The receiver enters the session ID and clicks "Start Receiving".
   - The frontend fetches file metadata (name, chunk count) from the backend via 'get_meta'.
   - For each chunk (in order):
     a. The frontend requests the chunk from the backend via 'get_chunk'.
        - The backend streams the chunk file from 'tmp/<session_id>/chunk_<index>'.
     b. After receiving the chunk, the frontend confirms receipt via 'confirm_chunk', which deletes the chunk from the server.
     c. The progress bar is updated.
   - After all chunks are received, the browser reassembles the file and offers it for download.

   API endpoints for receiver:
     - ?mode=api&action=get_meta&session_id=...      (GET) → {ok, file_name, total_chunks}
     - ?mode=api&action=get_chunk&session_id=...&chunk_index=... (GET) → chunk data
     - ?mode=api&action=confirm_chunk               (POST) with session_id, chunk_index → {ok}

4. File Structure:
   - tmp/
     - .password           (hashed password)
     - <session_id>/
         - meta.json       (file name, total_chunks)
         - chunk_0, chunk_1, ...

5. Security Considerations:
   - All access is protected by a password set on first run.
   - Session IDs are random and not guessable.
   - Only valid session IDs and chunk indices are accepted by the backend.
   - Uploaded files are not executable and are stored in a non-public directory.
   - Chunks are deleted from the server after successful download and confirmation by the receiver.
   - The 'tmp' directory is size-limited and checked for available disk space before accepting new chunks.
*/

// Configuration
$TMP_DIR = '/tmp/largefiletransfer';
$PASSWORD_FILE = $TMP_DIR . '/.password';
$CHUNK_SIZE = 1024 * 1024 * 2; // 5MB default chunk size
$TMP_SIZE_LIMIT = 1024 * 1024 * 50; // 100MB default tmp dir size

session_start();

// Ensure tmp directory exists
if (!is_dir($TMP_DIR)) {
    mkdir($TMP_DIR, 0700, true);
}

// Handle initial password setup
if (!file_exists($PASSWORD_FILE)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_password'])) {
        $password = trim($_POST['set_password']);
        if ($password !== '') {
            file_put_contents($PASSWORD_FILE, password_hash($password, PASSWORD_DEFAULT));
            $_SESSION['authenticated'] = true;
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $error = 'Password cannot be empty.';
        }
    }
    ?>
    <!DOCTYPE html>
    <html><head><title>Set Initial Password</title></head><body>
    <h2>Set Initial Password</h2>
    <?php if (!empty($error)) echo '<p style="color:red">' . htmlspecialchars($error) . '</p>'; ?>
    <form method="post">
        <input type="password" name="set_password" placeholder="Enter password" required />
        <button type="submit">Set Password</button>
    </form>
    </body></html>
    <?php
    exit;
}

// Password authentication for all further actions
if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        $password = trim($_POST['password']);
        $hash = file_get_contents($PASSWORD_FILE);
        if (password_verify($password, $hash)) {
            $_SESSION['authenticated'] = true;
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $error = 'Incorrect password.';
        }
    }
    ?>
    <!DOCTYPE html>
    <html><head><title>Password Required</title></head><body>
    <h2>Password Required</h2>
    <?php if (!empty($error)) echo '<p style="color:red">' . htmlspecialchars($error) . '</p>'; ?>
    <form method="post">
        <input type="password" name="password" placeholder="Enter password" required />
        <button type="submit">Login</button>
    </form>
    </body></html>
    <?php
    exit;
}

// Routing: choose sender or receiver
$mode = isset($_GET['mode']) ? $_GET['mode'] : null;

// === API ENDPOINTS FOR SENDER AND RECEIVER ===
if ($mode === 'api') {
    header('Content-Type: application/json');
    $action = isset($_GET['action']) ? $_GET['action'] : null;
    // Helper: get tmp dir size
    function get_dir_size($dir) {
        $size = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            $size += $file->getSize();
        }
        return $size;
    }
    // Sender actions
    if ($action === 'create_session') {
        $session_id = bin2hex(random_bytes(8));
        $session_dir = $TMP_DIR . '/' . $session_id;
        if (!mkdir($session_dir, 0700)) {
            echo json_encode(['ok' => false, 'error' => 'Failed to create session directory']);
            exit;
        }
        echo json_encode(['ok' => true, 'session_id' => $session_id]);
        exit;
    }
    if ($action === 'ready') {
        $tmp_size = get_dir_size($TMP_DIR);
        $free_space = disk_free_space($TMP_DIR);
        if ($tmp_size > $TMP_SIZE_LIMIT) {
            echo json_encode(['ok' => false, 'reason' => 'tmp_full']);
            exit;
        }
        if ($free_space < $CHUNK_SIZE * 2) {
            echo json_encode(['ok' => false, 'reason' => 'disk_full']);
            exit;
        }
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($action === 'upload_chunk') {
        $session_id = $_POST['session_id'] ?? '';
        $chunk_index = intval($_POST['chunk_index'] ?? -1);
        $total_chunks = intval($_POST['total_chunks'] ?? -1);
        $file_name = $_POST['file_name'] ?? '';
        if (!$session_id || $chunk_index < 0 || $total_chunks < 1 || !$file_name || !isset($_FILES['chunk'])) {
            error_log('Upload debug: Missing parameters. session_id=' . $session_id . ' chunk_index=' . $chunk_index . ' total_chunks=' . $total_chunks . ' file_name=' . $file_name . ' chunk isset=' . (isset($_FILES['chunk']) ? 'yes' : 'no'));
            echo json_encode(['ok' => false, 'error' => 'Missing parameters']);
            exit;
        }
        $session_dir = $TMP_DIR . '/' . preg_replace('/[^a-zA-Z0-9_]/', '', $session_id);
        if (!is_dir($session_dir)) {
            error_log('Upload debug: Invalid session dir: ' . $session_dir);
            echo json_encode(['ok' => false, 'error' => 'Invalid session']);
            exit;
        }
        $chunk_path = $session_dir . '/chunk_' . $chunk_index;
        error_log('Upload debug: tmp_name=' . $_FILES['chunk']['tmp_name'] . ' chunk_path=' . $chunk_path);
        if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $chunk_path)) {
            error_log('Upload debug: move_uploaded_file failed. Possible reasons: permissions, disk space, open_basedir, etc.');
            error_log('Upload debug: _FILES[chunk]=' . print_r($_FILES['chunk'], true));
            echo json_encode(['ok' => false, 'error' => 'Failed to save chunk']);
            exit;
        }
        if ($chunk_index === 0) {
            file_put_contents($session_dir . '/meta.json', json_encode([
                'file_name' => $file_name,
                'total_chunks' => $total_chunks
            ]));
        }
        echo json_encode(['ok' => true]);
        exit;
    }
    // Receiver actions
    if ($action === 'get_meta') {
        $session_id = $_GET['session_id'] ?? '';
        $session_dir = $TMP_DIR . '/' . preg_replace('/[^a-zA-Z0-9_]/', '', $session_id);
        $meta_file = $session_dir . '/meta.json';
        if (!is_dir($session_dir) || !file_exists($meta_file)) {
            echo json_encode(['ok' => false, 'error' => 'Session not found']);
            exit;
        }
        $meta = json_decode(file_get_contents($meta_file), true);
        $meta['ok'] = true;
        echo json_encode($meta);
        exit;
    }
    if ($action === 'get_chunk') {
        $session_id = $_GET['session_id'] ?? '';
        $chunk_index = intval($_GET['chunk_index'] ?? -1);
        $session_dir = $TMP_DIR . '/' . preg_replace('/[^a-zA-Z0-9_]/', '', $session_id);
        $chunk_path = $session_dir . '/chunk_' . $chunk_index;
        if (!is_dir($session_dir) || !file_exists($chunk_path)) {
            http_response_code(404);
            echo 'Chunk not found';
            exit;
        }
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($chunk_path));
        readfile($chunk_path);
        exit;
    }
    if ($action === 'confirm_chunk') {
        $session_id = $_POST['session_id'] ?? '';
        $chunk_index = intval($_POST['chunk_index'] ?? -1);
        $session_dir = $TMP_DIR . '/' . preg_replace('/[^a-zA-Z0-9_]/', '', $session_id);
        $chunk_path = $session_dir . '/chunk_' . $chunk_index;
        if (!is_dir($session_dir) || !file_exists($chunk_path)) {
            echo json_encode(['ok' => false, 'error' => 'Chunk not found']);
            exit;
        }
        unlink($chunk_path);
        echo json_encode(['ok' => true]);
        exit;
    }
    // Unknown action
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

if ($mode === 'sender') {
    // Sender HTML
    ?>
    <!DOCTYPE html>
    <html><head><title>Large File Transfer - Sender</title></head><body>
    <h2>Send a File</h2>
    <form id="sendForm">
        <label>Pick file to send: <input type="file" id="fileInput" required /></label><br><br>
        <button type="button" onclick="startSend()">Start Sending</button>
    </form>
    <div id="sendStatus"></div>
    <progress id="progressBar" value="0" max="100" style="width:300px; display:none;"></progress>
    <script>
    const CHUNK_SIZE = <?php echo json_encode($CHUNK_SIZE); ?>;
    let sessionId = null;
    async function startSend() {
        const fileInput = document.getElementById('fileInput');
        const sendBtn = document.querySelector('#sendForm button');
        sendBtn.disabled = true;
        if (!fileInput.files.length) return;
        const file = fileInput.files[0];
        document.getElementById('sendStatus').innerText = 'Creating session...';
        // Create session
        let resp = await fetch('?mode=api&action=create_session');
        let data = await resp.json();
        if (!data.ok) {
            document.getElementById('sendStatus').innerText = 'Error: ' + data.error;
            sendBtn.disabled = false;
            return;
        }
        sessionId = data.session_id;
        // Show session info and receiver page link in input with copy button
        const receiverUrl = location.origin + location.pathname + '?mode=receiver';
        document.getElementById('sendStatus').innerHTML =
            'Session ID: <b>' + sessionId + '</b><br>' +
            '<label>Receiver page link: <input type="text" id="receiverLink" value="' + receiverUrl + '" readonly size="40"></label>' +
            '<button type="button" onclick="copyReceiverLink()">Copy to clipboard</button>' +
            '<div id="sendProgress"></div>';
        const progressDiv = document.getElementById('sendProgress');
        window.copyReceiverLink = function() {
            const input = document.getElementById('receiverLink');
            input.select();
            input.setSelectionRange(0, 99999);
            document.execCommand('copy');
        };
        // Start chunking and uploading
        const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
        document.getElementById('progressBar').style.display = '';
        document.getElementById('progressBar').max = totalChunks;
        for (let i = 0; i < totalChunks; i++) {
            // Ask ready
            let readyData;
            while (true) {
                let readyResp = await fetch('?mode=api&action=ready');
                readyData = await readyResp.json();
                if (readyData.ok) break;
                if (readyData.reason === 'tmp_full') {
                    progressDiv.innerText = 'Backend not ready: tmp_full. Retrying in 1s...';
                    await new Promise(r => setTimeout(r, 1000));
                } else {
                    progressDiv.innerText = 'Backend not ready: ' + (readyData.reason || 'unknown');
                    sendBtn.disabled = false;
                    return;
                }
            }
            // Prepare chunk
            const start = i * CHUNK_SIZE;
            const end = Math.min(file.size, start + CHUNK_SIZE);
            const chunk = file.slice(start, end);
            const formData = new FormData();
            formData.append('session_id', sessionId);
            formData.append('chunk_index', i);
            formData.append('total_chunks', totalChunks);
            formData.append('file_name', file.name);
            formData.append('chunk', chunk, file.name + '.part' + i);
            // Upload chunk
            let uploadResp = await fetch('?mode=api&action=upload_chunk', {
                method: 'POST',
                body: formData
            });
            let uploadData = await uploadResp.json();
            if (!uploadData.ok) {
                progressDiv.innerText = 'Upload failed: ' + (uploadData.error || 'unknown');
                sendBtn.disabled = false;
                return;
            }
            document.getElementById('progressBar').value = i + 1;
            progressDiv.innerText = `Uploaded chunk ${i+1} of ${totalChunks}`;
        }
        progressDiv.innerText = 'File sent!';
    }
    </script>
    <p><a href="?">Back to main</a></p>
    </body></html>
    <?php
    exit;
} elseif ($mode === 'receiver') {
    // Receiver HTML
    $prefill_session = isset($_GET['session_id']) ? htmlspecialchars($_GET['session_id']) : '';
    ?>
    <!DOCTYPE html>
    <html><head><title>Large File Transfer - Receiver</title></head><body>
    <h2>Receive a File</h2>
    <form id="recvForm">
        <label>Session ID: <input type="text" id="sessionId" value="<?php echo $prefill_session; ?>" required /></label><br><br>
        <button type="button" onclick="startReceive()">Start Receiving</button>
    </form>
    <div id="recvStatus"></div>
    <progress id="progressBar" value="0" max="100" style="width:300px; display:none;"></progress>
    <script>
    <?php if ($prefill_session) { echo 'window.onload = function() { startReceive(); };'; } ?>
    async function startReceive() {
        const recvBtn = document.querySelector('#recvForm button');
        recvBtn.disabled = true;
        const sessionId = document.getElementById('sessionId').value.trim();
        if (!sessionId) return;
        document.getElementById('recvStatus').innerText = 'Fetching file info...';
        let metaResp = await fetch(`?mode=api&action=get_meta&session_id=${encodeURIComponent(sessionId)}`);
        let meta = await metaResp.json();
        if (!meta.ok) {
            document.getElementById('recvStatus').innerText = 'Error: ' + (meta.error || 'unknown');
            recvBtn.disabled = false;
            return;
        }
        const totalChunks = meta.total_chunks;
        const fileName = meta.file_name;
        let fileParts = [];
        document.getElementById('progressBar').style.display = '';
        document.getElementById('progressBar').max = totalChunks;
        for (let i = 0; i < totalChunks; i++) {
            while (true) {
                document.getElementById('recvStatus').innerText = `Downloading chunk ${i+1} of ${totalChunks}...`;
                let chunkResp = await fetch(`?mode=api&action=get_chunk&session_id=${encodeURIComponent(sessionId)}&chunk_index=${i}`);
                if (chunkResp.ok) {
                    let chunkData = await chunkResp.arrayBuffer();
                    fileParts.push(new Uint8Array(chunkData));
                    // Confirm chunk (delete from server)
                    let confirmResp = await fetch(`?mode=api&action=confirm_chunk`, {
                        method: 'POST',
                        body: new URLSearchParams({session_id: sessionId, chunk_index: i})
                    });
                    let confirmData = await confirmResp.json();
                    if (!confirmData.ok) {
                        document.getElementById('recvStatus').innerText = 'Failed to confirm chunk ' + i;
                        recvBtn.disabled = false;
                        return;
                    }
                    document.getElementById('progressBar').value = i + 1;
                    break;
                } else {
                    document.getElementById('recvStatus').innerText = `Chunk ${i+1} not available yet. Retrying in 1s...`;
                    await new Promise(r => setTimeout(r, 1000));
                }
            }
        }
        // Combine chunks and offer download
        const blob = new Blob(fileParts);
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = fileName;
        a.textContent = 'Download ' + fileName;
        document.getElementById('recvStatus').innerHTML = 'File received! ';
        document.getElementById('recvStatus').appendChild(a);
    }
    </script>
    <p><a href="?">Back to main</a></p>
    </body></html>
    <?php
    exit;
}

// Main landing page
?>
<!DOCTYPE html>
<html><head><title>Large File Transfer</title></head><body>
<h2>Large File Transfer</h2>
<p><a href="?mode=sender">I want to send a file</a></p>
<p><a href="?mode=receiver">I want to receive a file</a></p>
</body></html>
