const elements = {
  authView: document.querySelector("#authView"),
  chatView: document.querySelector("#chatView"),
  emailForm: document.querySelector("#emailForm"),
  codeForm: document.querySelector("#codeForm"),
  emailInput: document.querySelector("#emailInput"),
  nameInput: document.querySelector("#nameInput"),
  codeInput: document.querySelector("#codeInput"),
  backButton: document.querySelector("#backButton"),
  authStatus: document.querySelector("#authStatus"),
  presenceList: document.querySelector("#presenceList"),
  accountLabel: document.querySelector("#accountLabel"),
  logoutButton: document.querySelector("#logoutButton"),
  messageList: document.querySelector("#messageList"),
  messageForm: document.querySelector("#messageForm"),
  messageInput: document.querySelector("#messageInput"),
  fileInput: document.querySelector("#fileInput"),
  filePreview: document.querySelector("#filePreview"),
  fileName: document.querySelector("#fileName"),
  clearFileButton: document.querySelector("#clearFileButton"),
  sendButton: document.querySelector("#sendButton"),
};

let currentUser = null;
let currentEmail = "";
let latestSequence = 0;
let pollTimer = null;
let unreadCount = 0;
const baseTitle = document.title;
const maxAttachments = 5;

boot();

elements.emailForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  setAuthStatus("");
  const email = elements.emailInput.value.trim().toLowerCase();
  if (!email) return;

  setBusy(elements.emailForm, true);
  try {
    const result = await api("auth_start", {
      method: "POST",
      body: { email },
    });
    currentEmail = email;
    elements.emailForm.classList.add("hidden");
    elements.codeForm.classList.remove("hidden");
    elements.nameInput.value = suggestedName(email);
    elements.codeInput.value = result.devCode || "";
    elements.codeInput.focus();
    setAuthStatus(result.devCode ? `確認コード: ${result.devCode}` : "確認コードをメールで送りました。");
  } catch (error) {
    setAuthStatus(error.message, true);
  } finally {
    setBusy(elements.emailForm, false);
  }
});

elements.codeForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  setAuthStatus("");

  setBusy(elements.codeForm, true);
  try {
    const result = await api("auth_verify", {
      method: "POST",
      body: {
        email: currentEmail,
        code: elements.codeInput.value.trim(),
        displayName: elements.nameInput.value.trim(),
      },
    });

    currentUser = result.user;
    updatePresence(result.members || []);
    showChat();
  } catch (error) {
    setAuthStatus(error.message, true);
  } finally {
    setBusy(elements.codeForm, false);
  }
});

elements.backButton.addEventListener("click", () => {
  currentEmail = "";
  elements.codeForm.classList.add("hidden");
  elements.emailForm.classList.remove("hidden");
  elements.emailInput.focus();
  setAuthStatus("");
});

elements.logoutButton.addEventListener("click", async () => {
  try {
    await api("logout", { method: "POST" });
  } catch (_) {
  } finally {
    currentUser = null;
    latestSequence = 0;
    clearInterval(pollTimer);
    elements.messageList.replaceChildren();
    updatePresence([]);
    showAuth();
  }
});

elements.messageForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  const text = elements.messageInput.value.trim();
  const files = Array.from(elements.fileInput.files || []);
  if (!text && !files.length) return;
  if (files.length > maxAttachments) {
    alert(`添付ファイルは${maxAttachments}個まで選択できます。`);
    return;
  }

  elements.sendButton.disabled = true;
  try {
    const formData = new FormData();
    formData.append("text", text);
    for (const file of files) {
      formData.append("files[]", file);
    }

    const result = await api("messages", {
      method: "POST",
      formData,
    });
    elements.messageInput.value = "";
    clearSelectedFile();
    resizeMessageInput();
    updatePresence(result.members || []);
    appendMessages([result.message]);
  } catch (error) {
    alert(error.message);
  } finally {
    elements.sendButton.disabled = false;
    elements.messageInput.focus();
  }
});

elements.messageInput.addEventListener("input", resizeMessageInput);

elements.fileInput.addEventListener("change", updateFilePreview);

elements.clearFileButton.addEventListener("click", clearSelectedFile);

document.addEventListener("visibilitychange", () => {
  if (document.visibilityState === "visible") {
    resetUnread();
  }
});

async function boot() {
  try {
    const result = await api("me");
    currentUser = result.user;
    updatePresence(result.members || []);
    showChat();
  } catch (_) {
    showAuth();
  }
}

async function showChat() {
  clearInterval(pollTimer);
  elements.authView.classList.add("hidden");
  elements.chatView.classList.remove("hidden");
  elements.accountLabel.textContent = currentUser.displayName || "member";
  await refreshMessages(true);
  resetUnread();
  pollTimer = setInterval(refreshMessages, 1500);
  elements.messageInput.focus();
}

function showAuth() {
  elements.chatView.classList.add("hidden");
  elements.authView.classList.remove("hidden");
  elements.emailForm.classList.remove("hidden");
  elements.codeForm.classList.add("hidden");
  elements.emailInput.focus();
  resetUnread();
}

async function refreshMessages(initial = false) {
  try {
    const result = await api(`messages${latestSequence ? `&after=${latestSequence}` : ""}`);
    if (initial) {
      elements.messageList.replaceChildren();
    }
    const incomingFromOthers = appendMessages(result.messages);
    updatePresence(result.members || []);
    if (document.visibilityState !== "visible" && incomingFromOthers > 0) {
      incrementUnread(incomingFromOthers);
    }
    latestSequence = Math.max(latestSequence, result.latestSequence || 0);
  } catch (error) {
    if (error.status === 401) {
      currentUser = null;
      latestSequence = 0;
      clearInterval(pollTimer);
      elements.messageList.replaceChildren();
      showAuth();
    }
  }
}

function appendMessages(messages) {
  if (!messages.length) return 0;

  let incomingFromOthers = 0;

  const shouldStickToBottom =
    elements.messageList.scrollHeight - elements.messageList.scrollTop - elements.messageList.clientHeight < 90;

  for (const message of messages) {
    if (document.querySelector(`[data-message-id="${message.id}"]`)) continue;
    const item = document.createElement("li");
    item.className = `message${message.userId === currentUser.id ? " own" : ""}`;
    item.dataset.messageId = message.id;

    const meta = document.createElement("div");
    meta.className = "message-meta";
    const name = document.createElement("span");
    name.textContent = message.displayName || "member";
    const time = document.createElement("time");
    time.dateTime = message.createdAt;
    time.textContent = formatTime(message.createdAt);
    meta.append(name, time);

    const bubble = document.createElement("div");
    bubble.className = "message-bubble";
    if (message.text) {
      appendLinkedText(bubble, message.text);
    }
    for (const attachment of messageAttachments(message)) {
      appendAttachment(bubble, attachment);
    }

    item.append(meta, bubble);
    elements.messageList.append(item);
    latestSequence = Math.max(latestSequence, message.sequence || 0);
    if (message.userId !== currentUser.id) {
      incomingFromOthers += 1;
    }
  }

  if (shouldStickToBottom) {
    requestAnimationFrame(() => {
      elements.messageList.scrollTop = elements.messageList.scrollHeight;
    });
  }

  return incomingFromOthers;
}

function incrementUnread(count) {
  unreadCount += count;
  updateUnreadIndicators();
}

function resetUnread() {
  unreadCount = 0;
  updateUnreadIndicators();
}

function updateUnreadIndicators() {
  document.title = unreadCount > 0 ? `(${unreadCount}) ${baseTitle}` : baseTitle;

  if (typeof navigator.setAppBadge === "function") {
    if (unreadCount > 0) {
      navigator.setAppBadge(unreadCount).catch(() => {});
    } else if (typeof navigator.clearAppBadge === "function") {
      navigator.clearAppBadge().catch(() => {});
    }
  }
}

async function api(action, options = {}) {
  const headers = {};
  let body;
  if (options.formData) {
    body = options.formData;
  } else if (options.body) {
    headers["Content-Type"] = "application/json";
    body = JSON.stringify(options.body);
  }

  const response = await fetch(`api.php?action=${action}`, {
    method: options.method || "GET",
    headers,
    credentials: "same-origin",
    body,
  });
  const result = await response.json().catch(() => ({}));
  if (!response.ok) {
    const error = new Error(result.message || "通信に失敗しました。");
    error.status = response.status;
    throw error;
  }
  return result;
}

function setAuthStatus(message, isError = false) {
  elements.authStatus.textContent = message;
  elements.authStatus.classList.toggle("error", isError);
}

function setBusy(form, isBusy) {
  for (const control of form.querySelectorAll("button, input")) {
    control.disabled = isBusy;
  }
}

function resizeMessageInput() {
  elements.messageInput.style.height = "auto";
  elements.messageInput.style.height = `${elements.messageInput.scrollHeight}px`;
}

function formatTime(value) {
  return new Intl.DateTimeFormat("ja-JP", {
    hour: "2-digit",
    minute: "2-digit",
  }).format(new Date(value));
}

function suggestedName(email) {
  return email.split("@")[0].slice(0, 32);
}

function updatePresence(members) {
  if (!elements.presenceList) return;
  if (!members.length) {
    elements.presenceList.textContent = "-";
    return;
  }
  elements.presenceList.textContent = members.map((member) => member.displayName || "member").join(", ");
}

function updateFilePreview() {
  const files = Array.from(elements.fileInput.files || []);
  if (!files.length) {
    elements.filePreview.classList.add("hidden");
    elements.fileName.textContent = "";
    return;
  }

  if (files.length > maxAttachments) {
    alert(`添付ファイルは${maxAttachments}個まで選択できます。`);
    clearSelectedFile();
    return;
  }

  elements.fileName.textContent = files
    .map((file) => `${file.name} (${formatFileSize(file.size)})`)
    .join(", ");
  elements.filePreview.classList.remove("hidden");
}

function clearSelectedFile() {
  elements.fileInput.value = "";
  updateFilePreview();
}

function messageAttachments(message) {
  if (Array.isArray(message.attachments)) {
    return message.attachments;
  }
  return message.attachment ? [message.attachment] : [];
}

function appendAttachment(container, attachment) {
  if (container.childNodes.length) {
    container.append(document.createTextNode("\n"));
  }

  const link = document.createElement("a");
  link.className = "attachment-link";
  link.href = attachment.url;
  link.target = "_blank";
  link.rel = "noopener noreferrer";
  link.textContent = `${attachment.name || "attachment"} (${formatFileSize(attachment.size || 0)})`;
  container.append(link);
}

function formatFileSize(bytes) {
  if (bytes >= 1024 * 1024) {
    return `${(bytes / (1024 * 1024)).toFixed(1)}MB`;
  }
  if (bytes >= 1024) {
    return `${(bytes / 1024).toFixed(1)}KB`;
  }
  return `${bytes}B`;
}

function appendLinkedText(container, text) {
  const urlPattern = /https?:\/\/[^\s<>"']+/gi;
  let lastIndex = 0;
  let match;

  while ((match = urlPattern.exec(text)) !== null) {
    const rawUrl = trimTrailingUrlPunctuation(match[0]);
    const trailing = match[0].slice(rawUrl.length);

    if (match.index > lastIndex) {
      container.append(document.createTextNode(text.slice(lastIndex, match.index)));
    }

    const link = document.createElement("a");
    link.href = rawUrl;
    link.textContent = rawUrl;
    link.target = "_blank";
    link.rel = "noopener noreferrer";
    container.append(link);

    if (trailing) {
      container.append(document.createTextNode(trailing));
    }

    lastIndex = match.index + match[0].length;
  }

  if (lastIndex < text.length) {
    container.append(document.createTextNode(text.slice(lastIndex)));
  }
}

function trimTrailingUrlPunctuation(url) {
  return url.replace(/[.,!?;:)\]}、。！？；：）】]+$/u, "");
}
