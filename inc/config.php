<?php

// -------------------- CONFIG --------------------
const APP_TITLE   = 'Dropzone File Explorer';
const ROOT_DIR    = __DIR__ . '/../../files';
const STORAGE_DIR = __DIR__ . '/../../data';
const BASE_URL    = '';

// Optional Login (UI form, session-based)
const AUTH_ENABLE = true;
const AUTH_FILE   = STORAGE_DIR . '/auth/users.json';
const AUTH_COOKIE_NAME = 'EXPLORERSESS';

// Chunking
const CHUNK_SIZE_DEFAULT = 2 * 1024 * 1024; // 2MB
const MAX_UPLOAD_SIZE = 1024 * 1024 * 1024 * 1024; // 1024TB
const SAFE_TEXT_MAX = 5 * 1024 * 1024; // 5MB

// -------------------- BOOTSTRAP --------------------
if (!is_dir(ROOT_DIR)) @mkdir(ROOT_DIR, 0775, true);
if (!is_dir(STORAGE_DIR)) @mkdir(STORAGE_DIR, 0775, true);
if (!is_dir(STORAGE_DIR . '/chunks')) @mkdir(STORAGE_DIR . '/chunks', 0775, true);
if (!is_dir(STORAGE_DIR . '/shares')) @mkdir(STORAGE_DIR . '/shares', 0775, true);