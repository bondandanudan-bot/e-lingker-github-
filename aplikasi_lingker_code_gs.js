/**
 * Apps Script backend for the E-LINGKER web application.
 * Configure DATABASE_SPREADSHEET_ID in Script Properties before deployment.
 */
const LOGIN_USERS_PROPERTY = 'E_LINGKER_USERS';
const PASSWORD_PEPPER_PROPERTY = 'E_LINGKER_PASSWORD_PEPPER';
const DATABASE_ID_PROPERTY = 'DATABASE_SPREADSHEET_ID';
const DATA_SHEET_NAME = 'DataPengukuran';
const SESSION_TTL_SECONDS = 21600;
const PASSWORD_HASH_ITERATIONS = 10000;
const MAX_PHOTO_BYTES = 1024 * 1024;
const MAX_PHOTO_DATA_URL_LENGTH = 1536 * 1024;
const DATA_HEADERS = [
  'ID', 'Tanggal', 'Perusahaan', 'Area Tambang', 'Lokasi Uji',
  'Kategori Key', 'Kategori K3', 'Parameter', 'Hasil Nilai', 'Satuan',
  'NAB Reference', 'Status Evaluasi', 'Alat Ukur', 'Petugas', 'Catatan',
  'Foto Drive ID', 'Dibuat Oleh', 'Timestamp'
];

function doGet() {
  const htmlFileNames = [
    'aplikasi_lingker_index_html',
    'aplikasi_lingker_index_html.html'
  ];

  for (const htmlFileName of htmlFileNames) {
    try {
      return HtmlService.createHtmlOutputFromFile(htmlFileName)
        .setTitle('E-Lingker Pertambangan & Industri')
        .addMetaTag('viewport', 'width=device-width, initial-scale=1.0');
    } catch (error) {
      const errorMessage = error && error.message ? error.message : String(error);
      if (!errorMessage.includes(`No HTML file named ${htmlFileName} was found`)) {
        throw error;
      }
    }
  }

  throw new Error(
    'File HTML aplikasi belum ditemukan di proyek Apps Script. ' +
    'Tambahkan file HTML bernama "aplikasi_lingker_index_html" (tanpa .html), ' +
    'salin isi aplikasi_lingker_index_html.html ke file tersebut, lalu simpan dan deploy versi baru.'
  );
}

function getLoginStatus() {
  const properties = PropertiesService.getScriptProperties();
  const spreadsheetId = properties.getProperty(DATABASE_ID_PROPERTY);
  if (!spreadsheetId) {
    return {
      databaseConfigured: false,
      setupRequired: Object.keys(getLoginUsers_()).length === 0
    };
  }
  try {
    SpreadsheetApp.openById(requireDatabaseConfiguration_());
  } catch (error) {
    throw new Error('DATABASE_SPREADSHEET_ID tidak dapat dibuka oleh pemilik Apps Script. Periksa ID dan akses spreadsheet.');
  }
  return {
    databaseConfigured: true,
    setupRequired: Object.keys(getLoginUsers_()).length === 0
  };
}

function setupFirstAdmin(username, password) {
  const lock = LockService.getScriptLock();
  lock.waitLock(30000);
  try {
    requireDatabaseConfiguration_();
    getDataSheet_();
    const users = getLoginUsers_();
    if (Object.keys(users).length > 0) {
      throw new Error('Setup admin pertama sudah selesai. Silakan masuk.');
    }

    const account = createAccount_(username, password, 'admin');
    users[account.key] = account.value;
    saveLoginUsers_(users);
    return createSession_(account.value);
  } finally {
    lock.releaseLock();
  }
}

function login(username, password) {
  requireDatabaseConfiguration_();
  const rawUsername = String(username || '').trim();
  const validUsername = /^[a-z0-9._-]{3,32}$/i.test(rawUsername);
  const normalizedUsername = validUsername ? rawUsername.toLowerCase() : 'invalid';
  const rateKey = 'login_fail_' + hashToken_(normalizedUsername);
  const cache = CacheService.getScriptCache();
  const failedAttempts = Number(cache.get(rateKey) || 0);
  if (failedAttempts >= 5) {
    throw new Error('Terlalu banyak percobaan masuk. Coba lagi dalam 15 menit.');
  }

  const users = getLoginUsers_();
  const account = validUsername ? users[normalizedUsername] : null;
  const submittedPassword = typeof password === 'string' ? password : '';
  const passwordIsValid = account && submittedPassword.length <= 128
    ? constantTimeEquals_(account.passwordHash, hashPassword_(submittedPassword, account.salt))
    : false;

  if (!passwordIsValid) {
    cache.put(rateKey, String(failedAttempts + 1), 900);
    throw new Error('Username atau password salah.');
  }

  cache.remove(rateKey);
  return createSession_(account);
}

function logout(token) {
  CacheService.getScriptCache().remove(getSessionCacheKey_(token));
  return { success: true };
}

function createLoginUser(token, username, password) {
  const session = requireSession_(token);
  if (session.role !== 'admin') {
    throw new Error('Hanya admin yang dapat membuat akun.');
  }

  const lock = LockService.getScriptLock();
  lock.waitLock(30000);
  try {
    const users = getLoginUsers_();
    const account = createAccount_(username, password, 'user');
    if (users[account.key]) {
      throw new Error('Username sudah digunakan.');
    }
    users[account.key] = account.value;
    saveLoginUsers_(users);
    return { success: true };
  } finally {
    lock.releaseLock();
  }
}

function getRecordsFromSheet(token) {
  const session = requireSession_(token);
  const sheet = getDataSheet_();
  const values = sheet.getDataRange().getDisplayValues();
  const headerMap = getHeaderMap_(values[0]);
  const records = values.slice(1)
    .filter(row => row[headerMap.ID])
    .map(row => rowToRecord_(row, headerMap));
  return {
    records: records.reverse(),
    user: { username: session.username, role: session.role }
  };
}

function saveRecordToSheet(data, token) {
  const session = requireSession_(token);
  const record = saveRecord_(data, session.username);
  return { success: true, record: record };
}

function importLegacyRecord(token, legacyRecord) {
  const session = requireSession_(token);
  const recordId = legacyRecord && legacyRecord.id != null
    ? String(legacyRecord.id)
    : Utilities.getUuid();
  const sheet = getDataSheet_();
  const headerMap = getHeaderMap_(sheet.getRange(1, 1, 1, sheet.getLastColumn()).getDisplayValues()[0]);
  const existingRow = findRecordRow_(sheet, headerMap, recordId);
  if (existingRow) {
    return { duplicate: true };
  }

  const record = saveRecord_(legacyRecord, session.username, recordId);
  return { duplicate: false, record: record };
}

function getRecordPhoto(token, recordId) {
  requireSession_(token);
  const sheet = getDataSheet_();
  const headerMap = getHeaderMap_(sheet.getRange(1, 1, 1, sheet.getLastColumn()).getDisplayValues()[0]);
  const rowNumber = findRecordRow_(sheet, headerMap, String(recordId));
  if (!rowNumber) {
    throw new Error('Catatan tidak ditemukan.');
  }

  const row = sheet.getRange(rowNumber, 1, 1, sheet.getLastColumn()).getDisplayValues()[0];
  const fileId = row[headerMap['Foto Drive ID']];
  if (!fileId) {
    throw new Error('Foto catatan tidak tersedia.');
  }
  const blob = DriveApp.getFileById(fileId).getBlob();
  return {
    dataUrl: 'data:' + blob.getContentType() + ';base64,' + Utilities.base64Encode(blob.getBytes())
  };
}

function deleteRecordFromSheet(token, recordId) {
  requireSession_(token);
  const sheet = getDataSheet_();
  const headerMap = getHeaderMap_(sheet.getRange(1, 1, 1, sheet.getLastColumn()).getDisplayValues()[0]);
  const rowNumber = findRecordRow_(sheet, headerMap, String(recordId));
  if (!rowNumber) {
    throw new Error('Catatan tidak ditemukan.');
  }

  const fileId = sheet.getRange(rowNumber, headerMap['Foto Drive ID'] + 1).getDisplayValue();
  sheet.deleteRow(rowNumber);
  if (fileId) {
    DriveApp.getFileById(fileId).setTrashed(true);
  }
  return { success: true };
}

function clearRecordsFromSheet(token) {
  const session = requireSession_(token);
  if (session.role !== 'admin') {
    throw new Error('Hanya admin yang dapat menghapus seluruh catatan.');
  }
  const sheet = getDataSheet_();
  const lastRow = sheet.getLastRow();
  if (lastRow < 2) {
    return { success: true };
  }

  const headerMap = getHeaderMap_(sheet.getRange(1, 1, 1, sheet.getLastColumn()).getDisplayValues()[0]);
  const photoColumn = headerMap['Foto Drive ID'] + 1;
  const fileIds = sheet.getRange(2, photoColumn, lastRow - 1, 1)
    .getDisplayValues()
    .map(row => row[0])
    .filter(Boolean);
  sheet.deleteRows(2, lastRow - 1);
  fileIds.forEach(fileId => DriveApp.getFileById(fileId).setTrashed(true));
  return { success: true };
}

function saveRecord_(data, username, recordId) {
  requireDatabaseConfiguration_();
  if (!data || typeof data !== 'object') {
    throw new Error('Data pengukuran tidak valid.');
  }

  const numericValue = Number(data.hasil);
  if (!Number.isFinite(numericValue) || numericValue < 0) {
    throw new Error('Nilai pengukuran harus berupa angka nol atau lebih.');
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(String(data.tgl || ''))) {
    throw new Error('Tanggal pengukuran tidak valid.');
  }

  const record = {
    id: recordId || Utilities.getUuid(),
    tgl: textField_(data.tgl, 10, 'Tanggal'),
    perusahaan: textField_(data.perusahaan, 200, 'Perusahaan'),
    areaTambang: textField_(data.areaTambang, 200, 'Area tambang'),
    lokasi: textField_(data.lokasi, 300, 'Lokasi'),
    katKey: textField_(data.katKey, 50, 'Kategori'),
    kategoriName: textField_(data.kategoriName, 200, 'Nama kategori'),
    parameterName: textField_(data.parameterName, 300, 'Parameter'),
    hasil: numericValue,
    satuan: textField_(data.satuan, 50, 'Satuan'),
    nabText: textField_(data.nabText, 100, 'Acuan NAB'),
    isMemenuhi: data.isMemenuhi === true,
    alat: textField_(data.alat || '-', 200, 'Alat ukur'),
    petugas: textField_(data.petugas || '-', 200, 'Petugas'),
    catatan: textField_(data.catatan || '-', 1000, 'Catatan'),
    foto: null,
    fotoId: ''
  };
  const sheet = getDataSheet_();
  const headerMap = getHeaderMap_(sheet.getRange(1, 1, 1, sheet.getLastColumn()).getDisplayValues()[0]);
  if (findRecordRow_(sheet, headerMap, String(record.id))) {
    throw new Error('ID catatan sudah digunakan.');
  }

  let photoFile = null;
  if (data.foto) {
    photoFile = createPrivatePhoto_(data.foto, record.id);
    record.fotoId = photoFile.getId();
  }

  try {
    const row = new Array(sheet.getLastColumn()).fill('');
    setRowValue_(row, headerMap, 'ID', record.id);
    setRowValue_(row, headerMap, 'Tanggal', record.tgl);
    setRowValue_(row, headerMap, 'Perusahaan', record.perusahaan);
    setRowValue_(row, headerMap, 'Area Tambang', record.areaTambang);
    setRowValue_(row, headerMap, 'Lokasi Uji', record.lokasi);
    setRowValue_(row, headerMap, 'Kategori Key', record.katKey);
    setRowValue_(row, headerMap, 'Kategori K3', record.kategoriName);
    setRowValue_(row, headerMap, 'Parameter', record.parameterName);
    setRowValue_(row, headerMap, 'Hasil Nilai', record.hasil);
    setRowValue_(row, headerMap, 'Satuan', record.satuan);
    setRowValue_(row, headerMap, 'NAB Reference', record.nabText);
    setRowValue_(row, headerMap, 'Status Evaluasi', record.isMemenuhi ? 'Memenuhi NAB' : 'Melebihi NAB');
    setRowValue_(row, headerMap, 'Alat Ukur', record.alat);
    setRowValue_(row, headerMap, 'Petugas', record.petugas);
    setRowValue_(row, headerMap, 'Catatan', record.catatan);
    setRowValue_(row, headerMap, 'Foto Drive ID', record.fotoId);
    setRowValue_(row, headerMap, 'Dibuat Oleh', username);
    setRowValue_(row, headerMap, 'Timestamp', new Date().toISOString());

    const nextRow = sheet.getLastRow() + 1;
    sheet.getRange(nextRow, 1, 1, row.length).setNumberFormat('@');
    sheet.getRange(nextRow, 1, 1, row.length).setValues([row]);
    SpreadsheetApp.flush();
  } catch (error) {
    if (photoFile) {
      photoFile.setTrashed(true);
    }
    throw new Error('Gagal menyimpan catatan ke spreadsheet: ' + error.message);
  }

  return record;
}

function createPrivatePhoto_(dataUrl, recordId) {
  if (typeof dataUrl !== 'string' || dataUrl.length > MAX_PHOTO_DATA_URL_LENGTH) {
    throw new Error('Ukuran foto melebihi 1 MB setelah dikompres.');
  }
  const match = /^data:image\/(jpeg|png|webp);base64,([A-Za-z0-9+/=\r\n]+)$/.exec(String(dataUrl));
  if (!match) {
    throw new Error('Foto harus berformat JPG, PNG, atau WebP.');
  }

  const bytes = Utilities.base64Decode(match[2]);
  if (bytes.length > MAX_PHOTO_BYTES) {
    throw new Error('Ukuran foto melebihi 1 MB setelah dikompres.');
  }
  const mimeType = 'image/' + match[1];
  const extension = match[1] === 'jpeg' ? 'jpg' : match[1];
  const blob = Utilities.newBlob(bytes, mimeType, 'E-LINGKER-' + recordId + '.' + extension);
  return DriveApp.createFile(blob);
}

function getDataSheet_() {
  const spreadsheetId = requireDatabaseConfiguration_();
  const spreadsheet = SpreadsheetApp.openById(spreadsheetId);
  let sheet = spreadsheet.getSheetByName(DATA_SHEET_NAME);
  if (!sheet) {
    sheet = spreadsheet.insertSheet(DATA_SHEET_NAME);
  }

  if (sheet.getLastRow() === 0) {
    sheet.getRange(1, 1, 1, DATA_HEADERS.length).setValues([DATA_HEADERS]);
    return sheet;
  }

  const lastColumn = sheet.getLastColumn();
  const headers = sheet.getRange(1, 1, 1, lastColumn).getDisplayValues()[0];
  if (!headers.some(value => value.trim())) {
    throw new Error('Baris header sheet DataPengukuran kosong. Periksa spreadsheet sebelum digunakan.');
  }
  const missingHeaders = DATA_HEADERS.filter(header => headers.indexOf(header) === -1);
  if (missingHeaders.length) {
    sheet.getRange(1, lastColumn + 1, 1, missingHeaders.length).setValues([missingHeaders]);
  }
  getHeaderMap_(sheet.getRange(1, 1, 1, sheet.getLastColumn()).getDisplayValues()[0]);
  return sheet;
}

function getHeaderMap_(headers) {
  const map = {};
  headers.forEach((header, index) => {
    const name = String(header).trim();
    if (name) {
      if (map[name] != null) {
        throw new Error('Nama kolom spreadsheet duplikat: ' + name);
      }
      map[name] = index;
    }
  });
  DATA_HEADERS.forEach(header => {
    if (map[header] == null) {
      throw new Error('Kolom "' + header + '" tidak tersedia di sheet DataPengukuran.');
    }
  });
  return map;
}

function rowToRecord_(row, headerMap) {
  const value = name => row[headerMap[name]] || '';
  return {
    id: value('ID'),
    tgl: value('Tanggal'),
    perusahaan: value('Perusahaan'),
    areaTambang: value('Area Tambang'),
    lokasi: value('Lokasi Uji'),
    katKey: value('Kategori Key'),
    kategoriName: value('Kategori K3'),
    parameterName: value('Parameter'),
    hasil: Number(value('Hasil Nilai')),
    satuan: value('Satuan'),
    nabText: value('NAB Reference'),
    isMemenuhi: value('Status Evaluasi') === 'Memenuhi NAB',
    alat: value('Alat Ukur') || '-',
    petugas: value('Petugas') || '-',
    catatan: value('Catatan') || '-',
    fotoId: value('Foto Drive ID'),
    foto: null
  };
}

function findRecordRow_(sheet, headerMap, recordId) {
  const lastRow = sheet.getLastRow();
  if (lastRow < 2) return 0;
  const ids = sheet.getRange(2, headerMap.ID + 1, lastRow - 1, 1).getDisplayValues();
  const index = ids.findIndex(row => row[0] === recordId);
  return index < 0 ? 0 : index + 2;
}

function setRowValue_(row, headerMap, header, value) {
  row[headerMap[header]] = String(value == null ? '' : value);
}

function requireDatabaseConfiguration_() {
  const spreadsheetId = PropertiesService.getScriptProperties().getProperty(DATABASE_ID_PROPERTY);
  if (!spreadsheetId || !/^[A-Za-z0-9_-]{20,}$/.test(spreadsheetId)) {
    throw new Error('Script Property DATABASE_SPREADSHEET_ID belum dikonfigurasi dengan ID spreadsheet yang valid.');
  }
  return spreadsheetId;
}

function getLoginUsers_() {
  const rawUsers = PropertiesService.getScriptProperties().getProperty(LOGIN_USERS_PROPERTY);
  if (!rawUsers) return {};
  const users = JSON.parse(rawUsers);
  if (!users || typeof users !== 'object' || Array.isArray(users)) {
    throw new Error('Daftar akun di Script Properties tidak valid.');
  }
  return users;
}

function saveLoginUsers_(users) {
  PropertiesService.getScriptProperties().setProperty(LOGIN_USERS_PROPERTY, JSON.stringify(users));
}

function createAccount_(username, password, role) {
  const normalizedUsername = normalizeUsername_(username);
  const plainPassword = String(password || '');
  if (!/^[a-z0-9._-]{3,32}$/.test(normalizedUsername)) {
    throw new Error('Username harus 3-32 karakter: huruf, angka, titik, garis bawah, atau tanda hubung.');
  }
  if (plainPassword.length < 12 || plainPassword.length > 128) {
    throw new Error('Password harus memiliki 12-128 karakter.');
  }

  const salt = Utilities.getUuid() + Utilities.getUuid();
  return {
    key: normalizedUsername,
    value: {
      username: normalizedUsername,
      role: role,
      salt: salt,
      passwordHash: hashPassword_(plainPassword, salt)
    }
  };
}

function normalizeUsername_(username) {
  return String(username || '').trim().toLowerCase();
}

function hashPassword_(password, salt) {
  const pepper = getPasswordPepper_();
  let digest = Utilities.computeHmacSha256Signature(String(password), salt + pepper);
  for (let index = 1; index < PASSWORD_HASH_ITERATIONS; index++) {
    digest = Utilities.computeHmacSha256Signature(digest, pepper + salt);
  }
  return Utilities.base64Encode(digest);
}

function getPasswordPepper_() {
  const properties = PropertiesService.getScriptProperties();
  let pepper = properties.getProperty(PASSWORD_PEPPER_PROPERTY);
  if (!pepper) {
    pepper = Utilities.getUuid() + Utilities.getUuid();
    properties.setProperty(PASSWORD_PEPPER_PROPERTY, pepper);
  }
  return pepper;
}

function constantTimeEquals_(left, right) {
  if (typeof left !== 'string' || typeof right !== 'string' || left.length !== right.length) {
    return false;
  }
  let difference = 0;
  for (let index = 0; index < left.length; index++) {
    difference |= left.charCodeAt(index) ^ right.charCodeAt(index);
  }
  return difference === 0;
}

function createSession_(account) {
  const token = Utilities.getUuid() + Utilities.getUuid();
  CacheService.getScriptCache().put(
    getSessionCacheKey_(token),
    JSON.stringify({ username: account.username, role: account.role }),
    SESSION_TTL_SECONDS
  );
  return { token: token, user: { username: account.username, role: account.role } };
}

function requireSession_(token) {
  const cache = CacheService.getScriptCache();
  const sessionJson = cache.get(getSessionCacheKey_(token));
  if (!sessionJson) {
    throw new Error('Sesi login berakhir. Silakan masuk kembali.');
  }
  return JSON.parse(sessionJson);
}

function getSessionCacheKey_(token) {
  if (typeof token !== 'string' || !/^[A-Fa-f0-9-]{60,80}$/.test(token)) {
    throw new Error('Sesi login tidak valid.');
  }
  return 'session_' + hashToken_(token);
}

function hashToken_(value) {
  const digest = Utilities.computeDigest(
    Utilities.DigestAlgorithm.SHA_256,
    String(value),
    Utilities.Charset.UTF_8
  );
  return Utilities.base64EncodeWebSafe(digest);
}

function textField_(value, maxLength, label) {
  const text = String(value == null ? '' : value).trim();
  if (!text || text.length > maxLength) {
    throw new Error(label + ' wajib diisi dan maksimal ' + maxLength + ' karakter.');
  }
  return text;
}
