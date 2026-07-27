<?php

// -------------------- CONFIG --------------------
const APP_TITLE   = 'Dropzone File Explorer';
const ROOT_DIR    = __DIR__ . '/../../files';
const FILE_TMP_DIR = ROOT_DIR . '/._tmp';
const STORAGE_DIR = __DIR__ . '/../../data';
const BASE_URL    = '';

// Optional Login (UI form, session-based)
const AUTH_ENABLE = true;
const AUTH_FILE   = STORAGE_DIR . '/auth/users.json';
const AUTH_COOKIE_NAME = 'EXPLORERSESS';

// Chunking
const CHUNK_SIZE_DEFAULT = 10 * 1024 * 1024; // 10MB set limit by upload_max_filesize and post_max_size in php.ini
const MAX_UPLOAD_SIZE = 1024 * 1024 * 1024 * 1024; // 1024TB
const SAFE_TEXT_MAX = 5 * 1024 * 1024; // 5MB

// -------------------- BOOTSTRAP --------------------
if (!is_dir(ROOT_DIR)) @mkdir(ROOT_DIR, 0775, true);
if (!is_dir(FILE_TMP_DIR)) @mkdir(FILE_TMP_DIR, 0775, true);
if (!is_dir(STORAGE_DIR)) @mkdir(STORAGE_DIR, 0775, true);
if (!is_dir(STORAGE_DIR . '/chunks')) @mkdir(STORAGE_DIR . '/chunks', 0775, true);
if (!is_dir(STORAGE_DIR . '/shares')) @mkdir(STORAGE_DIR . '/shares', 0775, true);
if (!is_dir(STORAGE_DIR . '/tmp')) @mkdir(STORAGE_DIR . '/tmp', 0775, true);
