(function () {
    'use strict';

    var config = window.AICB_CONFIG || {};
    var history = [];
    var unread = 0;
    var hasOpenedOnce = false;

    function el(tag, className, attrs) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (attrs) {
            Object.keys(attrs).forEach(function (key) {
                node.setAttribute(key, attrs[key]);
            });
        }
        return node;
    }

    function buildLauncher(position) {
        var launcher = el('button', 'aicb-launcher aicb-pos-' + (position === 'bottom-left' ? 'left' : 'right'));
        if (config.avatarUrl) {
            var img = el('img');
            img.src = config.avatarUrl;
            launcher.appendChild(img);
        } else {
            launcher.textContent = '💬';
        }
        var badge = el('span', 'aicb-badge');
        badge.style.display = 'none';
        launcher.appendChild(badge);
        return { launcher: launcher, badge: badge };
    }

    function buildPopup(position) {
        var popup = el('div', 'aicb-popup aicb-pos-' + (position === 'bottom-left' ? 'left' : 'right'));

        var header = el('div', 'aicb-header');
        if (config.avatarUrl) {
            var img = el('img');
            img.src = config.avatarUrl;
            header.appendChild(img);
        }
        var name = el('span', 'aicb-name');
        name.textContent = config.assistantName || 'Assistant';
        header.appendChild(name);
        var closeBtn = el('button', 'aicb-close');
        closeBtn.textContent = '✕';
        header.appendChild(closeBtn);
        popup.appendChild(header);

        var messages = el('div', 'aicb-messages');
        popup.appendChild(messages);

        var quickReplies = el('div', 'aicb-quick-replies');
        (config.quickReplies || []).forEach(function (reply) {
            var btn = el('button', 'aicb-quick-reply');
            btn.textContent = reply.label;
            btn.addEventListener('click', function () {
                sendMessage(reply.message);
            });
            quickReplies.appendChild(btn);
        });
        popup.appendChild(quickReplies);

        var inputBar = el('div', 'aicb-input-bar');
        var input = el('input', '', { type: 'text', placeholder: 'Type a message...' });
        var sendBtn = el('button');
        sendBtn.textContent = 'Send';
        inputBar.appendChild(input);
        inputBar.appendChild(sendBtn);
        popup.appendChild(inputBar);

        function trySend() {
            var text = input.value.trim();
            if (!text) return;
            input.value = '';
            sendMessage(text);
        }

        sendBtn.addEventListener('click', trySend);
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') trySend();
        });

        return { popup: popup, messages: messages, closeBtn: closeBtn, input: input, sendBtn: sendBtn };
    }

    function appendMessage(messagesEl, role, text) {
        var msg = el('div', 'aicb-msg ' + (role === 'user' ? 'aicb-user' : 'aicb-bot'));
        msg.textContent = text;
        messagesEl.appendChild(msg);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    var refs;

    function setInputDisabled(disabled) {
        refs.input.disabled = disabled;
        refs.sendBtn.disabled = disabled;
        refs.sendBtn.textContent = disabled ? '...' : 'Send';
    }

    function sendMessage(text) {
        appendMessage(refs.messages, 'user', text);
        history.push({ role: 'user', content: text });
        setInputDisabled(true);

        var body = new URLSearchParams();
        body.append('action', 'aicb_send_message');
        body.append('nonce', config.nonce);
        body.append('message', text);
        body.append('page_url', window.location.href);
        history.forEach(function (turn, i) {
            body.append('history[' + i + '][role]', turn.role);
            body.append('history[' + i + '][content]', turn.content);
        });

        fetch(config.ajaxUrl, {
            method: 'POST',
            body: body,
        })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                setInputDisabled(false);
                var reply = (json.success && json.data && json.data.reply) || 'Sorry, something went wrong. Please try again.';
                appendMessage(refs.messages, 'assistant', reply);
                history.push({ role: 'assistant', content: reply });
                if (!isOpen()) {
                    unread += 1;
                    updateBadge();
                }
            })
            .catch(function () {
                setInputDisabled(false);
                appendMessage(refs.messages, 'assistant', 'Sorry, something went wrong. Please try again.');
            });
    }

    var launcherRefs, popupRefs;

    function isOpen() {
        return popupRefs.popup.classList.contains('aicb-open');
    }

    function updateBadge() {
        launcherRefs.badge.style.display = unread > 0 ? 'flex' : 'none';
        launcherRefs.badge.textContent = String(unread);
    }

    function openPopup() {
        popupRefs.popup.classList.add('aicb-open');
        unread = 0;
        updateBadge();
        if (!hasOpenedOnce && config.welcomeMessage) {
            appendMessage(popupRefs.messages, 'assistant', config.welcomeMessage);
            history.push({ role: 'assistant', content: config.welcomeMessage });
        }
        hasOpenedOnce = true;
    }

    function closePopup() {
        popupRefs.popup.classList.remove('aicb-open');
    }

    function init() {
        document.documentElement.style.setProperty('--aicb-accent', config.accentColor || '#4f46e5');

        launcherRefs = buildLauncher(config.position);
        popupRefs = buildPopup(config.position);
        refs = popupRefs;

        document.body.appendChild(launcherRefs.launcher);
        document.body.appendChild(popupRefs.popup);

        launcherRefs.launcher.addEventListener('click', function () {
            isOpen() ? closePopup() : openPopup();
        });
        popupRefs.closeBtn.addEventListener('click', closePopup);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
