(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.getElementById('aicb-profiles-manager');
        if (!root) {
            return;
        }

        var nonce = root.dataset.nonce;
        var selectedProfileId = null;
        var allProfiles = [];

        var listEl = document.createElement('div');
        var formEl = document.createElement('div');
        var docsEl = document.createElement('div');
        root.appendChild(listEl);
        root.appendChild(formEl);
        root.appendChild(docsEl);

        function post(action, fields) {
            var body = new URLSearchParams();
            body.append('action', action);
            body.append('nonce', nonce);
            Object.keys(fields || {}).forEach(function (key) {
                body.append(key, fields[key]);
            });
            return fetch(ajaxurl, { method: 'POST', body: body }).then(function (r) { return r.json(); });
        }

        function loadProfiles() {
            return post('aicb_list_profiles').then(function (json) {
                allProfiles = (json.success && json.data.profiles) || [];
                renderList();
            });
        }

        function renderList() {
            listEl.innerHTML = '';
            var heading = document.createElement('h3');
            heading.textContent = 'Profiles';
            listEl.appendChild(heading);

            allProfiles.forEach(function (profile) {
                var row = document.createElement('p');

                var label = document.createElement('strong');
                label.textContent = profile.is_default ? (profile.assistant_name + ' (Default)') : (profile.assistant_name + ' — ' + profile.path_prefix);
                row.appendChild(label);
                row.appendChild(document.createTextNode(' '));

                var selectBtn = document.createElement('button');
                selectBtn.type = 'button';
                selectBtn.className = 'button';
                selectBtn.textContent = 'Edit';
                selectBtn.addEventListener('click', function () { selectProfile(profile.id); });
                row.appendChild(selectBtn);
                row.appendChild(document.createTextNode(' '));

                if (!profile.is_default) {
                    var defaultBtn = document.createElement('button');
                    defaultBtn.type = 'button';
                    defaultBtn.className = 'button';
                    defaultBtn.textContent = 'Make Default';
                    defaultBtn.addEventListener('click', function () {
                        post('aicb_set_default_profile', { profile_id: profile.id }).then(loadProfiles);
                    });
                    row.appendChild(defaultBtn);
                    row.appendChild(document.createTextNode(' '));

                    var deleteBtn = document.createElement('button');
                    deleteBtn.type = 'button';
                    deleteBtn.className = 'button';
                    deleteBtn.textContent = 'Delete';
                    deleteBtn.addEventListener('click', function () {
                        post('aicb_delete_profile', { profile_id: profile.id }).then(function () {
                            if (selectedProfileId === profile.id) {
                                selectedProfileId = null;
                                formEl.innerHTML = '';
                                docsEl.innerHTML = '';
                            }
                            loadProfiles();
                        });
                    });
                    row.appendChild(deleteBtn);
                }

                listEl.appendChild(row);
            });

            var addBtn = document.createElement('button');
            addBtn.type = 'button';
            addBtn.className = 'button button-primary';
            addBtn.textContent = 'Add Profile';
            addBtn.addEventListener('click', function () { selectProfile(0); });
            listEl.appendChild(addBtn);
        }

        function blankProfile() {
            return {
                id: 0,
                path_prefix: '',
                is_default: false,
                assistant_name: 'Assistant',
                avatar_url: '',
                system_prompt: 'You are a helpful customer support assistant.',
                welcome_message: 'Hi! How can I help you today?',
                accent_color: '#4f46e5',
                widget_position: 'bottom-right',
                quick_replies: [],
            };
        }

        function selectProfile(id) {
            selectedProfileId = id;
            var profile = id === 0 ? blankProfile() : allProfiles.filter(function (p) { return p.id === id; })[0];
            renderForm(profile);
            if (id === 0) {
                docsEl.innerHTML = '<p>Save this profile first to manage its documents.</p>';
            } else {
                renderDocumentManager(id);
            }
        }

        function renderForm(profile) {
            formEl.innerHTML = '';
            var heading = document.createElement('h3');
            heading.textContent = profile.id ? 'Edit Profile' : 'New Profile';
            formEl.appendChild(heading);

            var fields = {};

            function textField(labelText, key, value) {
                var p = document.createElement('p');
                var label = document.createElement('label');
                label.textContent = labelText + ' ';
                var input = document.createElement('input');
                input.type = 'text';
                input.value = value || '';
                fields[key] = input;
                label.appendChild(input);
                p.appendChild(label);
                formEl.appendChild(p);
            }

            textField('Path Prefix (e.g. /members/harry)', 'path_prefix', profile.path_prefix);
            textField('Assistant Name', 'assistant_name', profile.assistant_name);
            textField('Avatar URL', 'avatar_url', profile.avatar_url);
            textField('Welcome Message', 'welcome_message', profile.welcome_message);
            textField('Accent Color', 'accent_color', profile.accent_color);

            var promptP = document.createElement('p');
            var promptLabel = document.createElement('label');
            promptLabel.textContent = 'System Prompt ';
            var promptArea = document.createElement('textarea');
            promptArea.rows = 5;
            promptArea.value = profile.system_prompt || '';
            fields.system_prompt = promptArea;
            promptLabel.appendChild(promptArea);
            promptP.appendChild(promptLabel);
            formEl.appendChild(promptP);

            var posP = document.createElement('p');
            var posLabel = document.createElement('label');
            posLabel.textContent = 'Widget Position ';
            var posSelect = document.createElement('select');
            ['bottom-right', 'bottom-left'].forEach(function (val) {
                var opt = document.createElement('option');
                opt.value = val;
                opt.textContent = val;
                if (val === profile.widget_position) opt.selected = true;
                posSelect.appendChild(opt);
            });
            fields.widget_position = posSelect;
            posLabel.appendChild(posSelect);
            posP.appendChild(posLabel);
            formEl.appendChild(posP);

            var defaultP = document.createElement('p');
            var defaultLabel = document.createElement('label');
            var defaultCheckbox = document.createElement('input');
            defaultCheckbox.type = 'checkbox';
            defaultCheckbox.checked = !!profile.is_default;
            fields.is_default = defaultCheckbox;
            defaultLabel.appendChild(defaultCheckbox);
            defaultLabel.appendChild(document.createTextNode(' Default profile'));
            defaultP.appendChild(defaultLabel);
            formEl.appendChild(defaultP);

            var qrHeading = document.createElement('p');
            qrHeading.innerHTML = '<strong>Quick Replies</strong>';
            formEl.appendChild(qrHeading);
            var qrList = document.createElement('div');
            formEl.appendChild(qrList);
            var quickReplyRows = [];

            function addQuickReplyRow(label, message) {
                var row = document.createElement('p');
                var labelInput = document.createElement('input');
                labelInput.type = 'text';
                labelInput.placeholder = 'Button label';
                labelInput.value = label || '';
                var messageInput = document.createElement('input');
                messageInput.type = 'text';
                messageInput.placeholder = 'Message to send';
                messageInput.value = message || '';
                row.appendChild(labelInput);
                row.appendChild(messageInput);
                qrList.appendChild(row);
                quickReplyRows.push({ label: labelInput, message: messageInput });
            }

            (profile.quick_replies || []).forEach(function (qr) { addQuickReplyRow(qr.label, qr.message); });

            var addQrBtn = document.createElement('button');
            addQrBtn.type = 'button';
            addQrBtn.className = 'button';
            addQrBtn.textContent = 'Add Quick Reply';
            addQrBtn.addEventListener('click', function () { addQuickReplyRow('', ''); });
            formEl.appendChild(addQrBtn);

            var saveBtn = document.createElement('button');
            saveBtn.type = 'button';
            saveBtn.className = 'button button-primary';
            saveBtn.textContent = 'Save Profile';
            saveBtn.style.display = 'block';
            saveBtn.addEventListener('click', function () {
                var payload = {
                    'profile_id': profile.id,
                    'profile[path_prefix]': fields.path_prefix.value,
                    'profile[assistant_name]': fields.assistant_name.value,
                    'profile[avatar_url]': fields.avatar_url.value,
                    'profile[system_prompt]': fields.system_prompt.value,
                    'profile[welcome_message]': fields.welcome_message.value,
                    'profile[accent_color]': fields.accent_color.value,
                    'profile[widget_position]': fields.widget_position.value,
                    'profile[is_default]': fields.is_default.checked ? '1' : '0',
                };

                var body = new URLSearchParams();
                body.append('action', 'aicb_save_profile');
                body.append('nonce', nonce);
                Object.keys(payload).forEach(function (key) { body.append(key, payload[key]); });

                quickReplyRows.forEach(function (row, i) {
                    if (row.label.value && row.message.value) {
                        body.append('profile[quick_replies][' + i + '][label]', row.label.value);
                        body.append('profile[quick_replies][' + i + '][message]', row.message.value);
                    }
                });

                fetch(ajaxurl, { method: 'POST', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (json) {
                        if (json.success) {
                            loadProfiles().then(function () {
                                selectProfile(json.data.profile_id);
                            });
                        } else {
                            alert((json.data && json.data.message) || 'Could not save profile.');
                        }
                    });
            });
            formEl.appendChild(saveBtn);
        }

        function renderDocumentManager(profileId) {
            docsEl.innerHTML = '';
            var heading = document.createElement('h4');
            heading.textContent = 'Knowledge Base Documents';
            docsEl.appendChild(heading);

            var listContainer = document.createElement('div');
            docsEl.appendChild(listContainer);

            function refreshDocs() {
                post('aicb_list_documents', { profile_id: profileId }).then(function (json) {
                    var docs = (json.success && json.data.documents) || [];
                    listContainer.innerHTML = '';
                    if (!docs.length) {
                        listContainer.innerHTML = '<p>No documents uploaded for this profile yet.</p>';
                        return;
                    }
                    docs.forEach(function (doc) {
                        var row = document.createElement('p');
                        row.textContent = doc.filename + ' (' + doc.chunk_count + ' chunk(s), uploaded ' + doc.uploaded_at + ') ';
                        var delBtn = document.createElement('button');
                        delBtn.type = 'button';
                        delBtn.className = 'button';
                        delBtn.textContent = 'Delete';
                        delBtn.addEventListener('click', function () {
                            post('aicb_delete_document', { document_id: doc.id }).then(refreshDocs);
                        });
                        row.appendChild(delBtn);
                        listContainer.appendChild(row);
                    });
                });
            }

            var fileInput = document.createElement('input');
            fileInput.type = 'file';
            fileInput.accept = '.txt,.pdf';

            var uploadButton = document.createElement('button');
            uploadButton.type = 'button';
            uploadButton.className = 'button';
            uploadButton.textContent = 'Upload Document';

            var statusEl = document.createElement('p');

            docsEl.appendChild(fileInput);
            docsEl.appendChild(uploadButton);
            docsEl.appendChild(statusEl);

            uploadButton.addEventListener('click', function () {
                if (!fileInput.files.length) {
                    statusEl.textContent = 'Please choose a file first.';
                    return;
                }

                var formData = new FormData();
                formData.append('action', 'aicb_upload_document');
                formData.append('nonce', nonce);
                formData.append('profile_id', profileId);
                formData.append('file', fileInput.files[0]);

                statusEl.textContent = 'Uploading...';
                uploadButton.disabled = true;

                fetch(ajaxurl, { method: 'POST', body: formData })
                    .then(function (res) { return res.json(); })
                    .then(function (json) {
                        uploadButton.disabled = false;
                        if (json.success) {
                            statusEl.textContent = 'Uploaded "' + fileInput.files[0].name + '" (' + json.data.chunks + ' chunk(s) indexed).';
                            fileInput.value = '';
                            refreshDocs();
                        } else {
                            statusEl.textContent = 'Error: ' + (json.data && json.data.message ? json.data.message : 'Upload failed.');
                        }
                    })
                    .catch(function () {
                        uploadButton.disabled = false;
                        statusEl.textContent = 'Upload failed. Please try again.';
                    });
            });

            refreshDocs();
        }

        loadProfiles();
    });
})();
