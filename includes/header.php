<?php
if(session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="et">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Kivitickets</title>

<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Fraunces:wght@700;900&display=swap');

* {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
}

:root {
  --bg: 
  --box: 
  --text: 
  --muted: 
  --border: 
  --accent: 
  --success: 
  --error: 
}

body {
  min-height: 100vh;
  background: var(--bg);
  color: var(--text);
  font-family: 'DM Mono', monospace;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
}

.container {
  width: 100%;
  max-width: 720px;
  background: var(--box);
  border: 1px solid var(--border);
  padding: 2rem;
}

.logo {
  font-family: 'Fraunces', serif;
  font-size: 2rem;
  font-weight: 900;
  color: var(--accent);
  margin-bottom: 0.25rem;
}

.subtitle {
  color: var(--muted);
  font-size: 0.75rem;
  margin-bottom: 2rem;
  text-transform: uppercase;
  letter-spacing: 2px;
}

h2 {
  margin-bottom: 1.5rem;
}

label {
  display: block;
  font-size: 0.75rem;
  color: var(--muted);
  margin-bottom: 0.4rem;
}

input,
select {
  width: 100%;
  padding: 0.85rem;
  margin-bottom: 1.2rem;
  border: 1px solid var(--border);
  background: 
  color: 
  font-family: 'DM Mono', monospace;
  font-size: 0.95rem;
}

input:focus,
select:focus {
  outline: none;
  border-color: var(--accent);
}

button,
.btn {
  display: block;
  width: 100%;
  padding: 0.9rem;
  background: var(--accent);
  color: 
  border: none;
  font-size: 0.85rem;
  text-transform: uppercase;
  letter-spacing: 1px;
  cursor: pointer;
  text-align: center;
  text-decoration: none;
  margin-top: 0.6rem;
}

button:hover,
.btn:hover {
  opacity: 0.9;
}

.btn.secondary {
  background: transparent;
  color: var(--accent);
  border: 1px solid var(--border);
}

.btn.danger {
  background: var(--error);
  color: 
}

.check-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 1rem;
}

.check-row input {
  width: auto;
}

.card {
  border: 1px solid var(--border);
  padding: 1rem;
  margin-bottom: 1rem;
}

.message {
  padding: 1rem;
  margin-bottom: 1rem;
  border: 1px solid;
}

.message.success {
  background: 
  border-color: var(--success);
  color: var(--success);
}

.message.error {
  background: 
  border-color: var(--error);
  color: var(--error);
}

.big-code {
  font-size: 1.3rem;
  color: var(--accent);
  word-break: break-all;
}

.menu-grid {
  display: grid;
  gap: 0.6rem;
}

.message.info { 
  background: 
  border-left: 4px solid 
  color: 
}

</style>
</head>

<body>
<div class="container">

<div class="logo">KiviTickets</div>
<div class="subtitle">Rongipiletite süsteem</div>
