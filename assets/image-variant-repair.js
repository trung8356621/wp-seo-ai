(() => {
    const config = window.OMI_IMAGE_REPAIR || {};
    const scanButton = document.getElementById('omi-repair-scan');
    const runButton = document.getElementById('omi-repair-run');
    const status = document.getElementById('omi-repair-status');
    const progress = document.getElementById('omi-repair-progress');
    const table = document.getElementById('omi-repair-table');
    const tbody = table?.querySelector('tbody');
    let issueIds = [];

    const escapeHtml = (value) =>
        String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');

    const setStatus = (message, warning = false) => {
        status.hidden = false;
        status.textContent = message;
        status.classList.toggle('omi-seo-ai-bridge-notice--warn', warning);
        status.classList.toggle('omi-seo-ai-bridge-notice--info', !warning);
    };

    const request = async (action, payload = {}) => {
        const body = new URLSearchParams({ action, nonce: config.nonce, ...payload });
        const response = await fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body,
        });
        const json = await response.json();
        if (!json?.success) throw new Error(json?.data?.message || 'Yêu cầu thất bại.');
        return json.data;
    };

    const appendRows = (rows) => {
        rows.forEach((row) => {
            const tr = document.createElement('tr');
            tr.dataset.attachmentId = row.attachment_id;
            tr.innerHTML = `
                <td>${row.attachment_id}</td>
                <td><a href="${escapeHtml(row.full_url)}" target="_blank" rel="noreferrer">${escapeHtml(row.title || '(không tên)')}</a></td>
                <td><code>${escapeHtml(row.full_file)}</code></td>
                <td>${row.mismatched.map((item) => `<code>${escapeHtml(item.file)}</code>`).join('<br>')}</td>
                <td class="omi-repair-row-status">Chờ sửa</td>
            `;
            tbody.appendChild(tr);
        });
    };

    scanButton?.addEventListener('click', async () => {
        scanButton.disabled = true;
        runButton.disabled = true;
        issueIds = [];
        tbody.innerHTML = '';
        table.hidden = true;
        progress.hidden = false;
        progress.removeAttribute('value');

        let page = 1;
        let scanned = 0;
        try {
            while (true) {
                const data = await request('omi_scan_image_variants', { page: String(page) });
                scanned += Number(data.scanned || 0);
                issueIds.push(...data.ids.map(Number));
                appendRows(data.rows || []);
                setStatus(`Đã quét ${scanned} ảnh, phát hiện ${issueIds.length} attachment lỗi.`);
                if (data.done) break;
                page += 1;
            }
            table.hidden = issueIds.length === 0;
            runButton.disabled = issueIds.length === 0;
        } catch (error) {
            setStatus(error.message, true);
        } finally {
            progress.hidden = true;
            scanButton.disabled = false;
        }
    });

    runButton?.addEventListener('click', async () => {
        if (!issueIds.length || !window.confirm(`Sửa ${issueIds.length} attachment lỗi?`)) return;

        runButton.disabled = true;
        scanButton.disabled = true;
        progress.hidden = false;
        progress.max = issueIds.length;
        progress.value = 0;
        let repaired = 0;
        let failed = 0;

        for (let index = 0; index < issueIds.length; index += 5) {
            const ids = issueIds.slice(index, index + 5);
            try {
                const data = await request('omi_repair_image_variants', { ids: ids.join(',') });
                (data.results || []).forEach((result) => {
                    const cell = tbody.querySelector(`[data-attachment-id="${result.attachment_id}"] .omi-repair-row-status`);
                    if (result.success) {
                        repaired += 1;
                        if (cell) cell.textContent = `Đã sửa: xóa ${result.deleted}, tạo ${result.regenerated}`;
                    } else {
                        failed += 1;
                        if (cell) cell.textContent = result.message || 'Lỗi';
                    }
                });
            } catch (error) {
                failed += ids.length;
                ids.forEach((id) => {
                    const cell = tbody.querySelector(`[data-attachment-id="${id}"] .omi-repair-row-status`);
                    if (cell) cell.textContent = error.message;
                });
            }

            progress.value = Math.min(index + ids.length, issueIds.length);
            setStatus(`Đã xử lý ${progress.value}/${issueIds.length}. Thành công ${repaired}, lỗi ${failed}.`, failed > 0);
        }

        scanButton.disabled = false;
        progress.hidden = true;
    });
})();
