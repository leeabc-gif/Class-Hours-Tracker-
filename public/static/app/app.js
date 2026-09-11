/* =====================================================================
 * 课时通 · 多教师课时统计与课酬核算 —— 前端单页应用
 * 对接后端：ThinkPHP 5.1 /api/* 接口（JSON）
 * 会话：服务端 PHP Session（Cookie 自动携带）
 * ===================================================================== */
(function () {
  'use strict';

  // ---------- 全局命名空间 ----------
  const App = {
    user: null,        // 当前登录用户
    boot: null,        // 登录后一次性启动数据
    page: 'dashboard', // 当前页面
    charts: {},        // 图表实例缓存
    _chartsReady: false,
    _quickCtx: null,   // 快速录入上下文（复用课程时记录选中的课程）
  };
  window.App = App;
  window.KS = App; // 供 inline onclick 使用

  // =====================================================================
  // 1. 基础工具
  // =====================================================================
  const $ = (sel, root) => (root || document).querySelector(sel);
  const $$ = (sel, root) => Array.prototype.slice.call((root || document).querySelectorAll(sel));
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  // 把字符串安全嵌入 onclick 的 JS 字面量：JSON.stringify 处理引号/反斜杠，
  // 再经 esc 让双引号在 HTML 属性里安全解码还原，任何名称（含 ' " \）都不会破坏脚本。
  const jsStr = (s) => esc(JSON.stringify(String(s == null ? '' : s)));

  function fmtMoney(v) {
    const n = Number(v) || 0;
    return n.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function fmtNum(v) { return (Number(v) || 0).toLocaleString('zh-CN'); }

  // Toast
  function toast(msg, type) {
    const wrap = $('#toastWrap'); if (!wrap) return;
    const el = document.createElement('div');
    el.className = 'wt' + (type === 'err' ? ' err' : type === 'warn' ? ' warn' : '');
    el.innerHTML = '<i class="bi bi-' + (type === 'err' ? 'exclamation-circle' : type === 'warn' ? 'exclamation-triangle' : 'check-circle') + '"></i><div>' + esc(msg) + '</div>';
    wrap.appendChild(el);
    requestAnimationFrame(() => el.classList.add('show'));
    setTimeout(() => { el.classList.remove('show'); setTimeout(() => el.remove(), 300); }, 2800);
  }
  App.toast = toast;

  // ====================================================================
  // 班级多选控件（class-picker）
  // 用法：<div class="class-picker" data-target="qClasses"></div><input type="hidden" id="qClasses">
  // 提供 classPickerInit(boxId, selectedArr?) / classPickerSet(boxId, csvOrArr)
  // 选中状态写到对应 hidden input（逗号分隔，兼容老逻辑）
  // ====================================================================
  function classPickerRead(box){
    const sel = box._sel || new Set();
    return Array.from(sel);
  }
  function classPickerWrite(box){
    const id = box.dataset.target;
    const inp = document.getElementById(id);
    if (inp) inp.value = classPickerRead(box).join(',');
  }
  function classPickerRender(box){
    const all = (App.boot.classes || []);
    const depts = (window.depts || []); // 可能为空——dept 名非必需
    const sel = box._sel || (box._sel = new Set());
    const q = (box._q || '').trim().toLowerCase();
    // 已选标签区
    const tags = Array.from(sel).map(id => {
      const c = all.find(x => x.id === id);
      const name = c ? c.name : '#' + id;
      return '<span class="cp-tag">' + esc(name) + ' <i class="bi bi-x" data-rm="' + id + '"></i></span>';
    }).join('');
    // 下拉候选
    const list = all.filter(c => c.status === undefined || c.status === 1)
      .filter(c => !q || c.name.toLowerCase().indexOf(q) >= 0)
      .filter(c => !sel.has(c.id))
      .slice(0, 30);
    const opts = list.map(c => {
      const dn = c.department_id ? (depts.find(d => d.id === c.department_id) || {}).name || '' : '';
      return '<div class="cp-opt" data-id="' + c.id + '">' + esc(c.name) + (dn ? ' <span class="cp-dept">' + esc(dn) + '</span>' : '') + '</div>';
    }).join('');
    box.innerHTML =
      '<div class="cp-tags">' + (tags || '<span class="cp-empty text-secondary">暂未选</span>') + '</div>' +
      '<div class="cp-addrow"><input class="form-control form-control-sm cp-q" placeholder="搜索 / 过滤班级（多选用逗号）" value="' + esc(box._q || '') + '">' +
      '<button type="button" class="btn btn-sm btn-outline-primary cp-toggle"><i class="bi bi-plus"></i></button></div>' +
      '<div class="cp-pop" style="display:' + (box._open ? 'block' : 'none') + '">' +
        (opts || '<div class="text-secondary small p-2">没有可选班级</div>') +
      '</div>';
  }
  function classPickerInit(boxId, selectedCsv){
    const box = document.getElementById(boxId);
    if (!box || box._inited) { classPickerWrite(box); return; }
    box._inited = true;
    const init = (selectedCsv || '').toString().split(/[,,，、\s]+/).filter(Boolean).map(x => parseInt(x, 10)).filter(x => x > 0);
    box._sel = new Set(init);
    classPickerRender(box);
    classPickerWrite(box);
    // 事件委托
    box.addEventListener('click', function(ev){
      const rm = ev.target.closest('[data-rm]');
      if (rm) { box._sel.delete(parseInt(rm.dataset.rm, 10)); classPickerRender(box); classPickerWrite(box); ev.preventDefault(); return; }
      const opt = ev.target.closest('.cp-opt');
      if (opt) {
        const id = parseInt(opt.dataset.id, 10);
        box._sel.add(id); box._q = ''; classPickerRender(box); classPickerWrite(box); ev.preventDefault();
        // 焦点回到搜索框，方便继续选
        const q = box.querySelector('.cp-q'); if (q) q.focus();
        return;
      }
      const tog = ev.target.closest('.cp-toggle');
      if (tog) { box._open = !box._open; classPickerRender(box); ev.preventDefault(); return; }
    });
    box.addEventListener('input', function(ev){
      if (ev.target.classList.contains('cp-q')) {
        box._q = ev.target.value; box._open = true; classPickerRender(box);
      }
    });
    box.addEventListener('keydown', function(ev){
      if (ev.target.classList.contains('cp-q') && (ev.key === 'Enter' || ev.key === ',')) {
        // 回车或逗号 → 把输入当作新班级名（不写入 ks_class，仅作临时项）
        const v = (box._q || '').replace(/[,,，、\s]+$/, '').trim();
        if (v) {
          // 临时班级：用 -1 占位 + 自定义名
          box._custom = box._custom || [];
          if (!box._custom.includes(v)) box._custom.push(v);
          box._q = ''; classPickerRender(box); classPickerWrite(box);
        }
        ev.preventDefault();
      }
    });
  }
  function classPickerSet(boxId, csv){
    const box = document.getElementById(boxId);
    if (!box) return;
    const init = (csv || '').toString().split(/[,,，、\s]+/).filter(Boolean).map(x => parseInt(x, 10)).filter(x => x > 0);
    box._sel = new Set(init);
    if (box._inited) { classPickerRender(box); classPickerWrite(box); }
  }
  window.classPickerInit = classPickerInit;
  window.classPickerSet = classPickerSet;

  function downloadCSV(url) {
    // 带 Session 的下载：用隐藏 iframe / 直接导航到该 url（Cookie 自动带上）
    const a = document.createElement('a');
    a.href = url; a.style.display = 'none';
    document.body.appendChild(a); a.click(); a.remove();
  }

  // =====================================================================
  // 2. API 客户端
  // =====================================================================
  // 全局 CSRF token；登录/页面启动时由 fetchCsrf() 拉取并注入所有写请求
  window._csrfToken = window._csrfToken || '';
  async function fetchCsrf(){
    if (window._csrfToken) return window._csrfToken;
    try {
      const r = await fetch('/api/auth/csrfToken', { credentials: 'same-origin' });
      if (r.ok) {
        const d = await r.json();
        if (d && d.code === 0 && d.data && d.data.token) {
          window._csrfToken = d.data.token;
        }
      }
    } catch (e) { /* 离线场景忽略，进入 app 后会再尝试 */ }
    return window._csrfToken;
  }
  // 启动时主动拉一次：保证登录前就有 token 可用
  fetchCsrf();

  async function api(method, path, body, opts) {
    opts = opts || {};
    const opt = { method: method, credentials: 'same-origin' };
    const headers = {};
    const m = String(method || 'GET').toUpperCase();
    // 写操作必须带 CSRF token（登录/注册/csrfToken/install 已在服务端白名单）
    if (m !== 'GET' && m !== 'HEAD' && m !== 'OPTIONS') {
      const t = await fetchCsrf();
      if (t) headers['X-CSRF-Token'] = t;
    }
    if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
      opt.body = JSON.stringify(body);
    }
    if (Object.keys(headers).length) opt.headers = headers;
    let resp;
    try {
      resp = await fetch(path, opt);
    } catch (e) {
      toast('网络请求失败，请确认服务已启动', 'err');
      throw e;
    }
    let data = null;
    const ct = resp.headers.get('content-type') || '';
    if (ct.indexOf('json') >= 0) {
      try { data = await resp.json(); } catch (e) { data = null; }
    } else {
      // 下载流
      return { raw: true, status: resp.status };
    }
    if (data === null) {
      // 后端返回了非 JSON（多为 500 错误页/被重定向），原来会静默失败，用户感觉"点了没反应"
      toast('服务异常（HTTP ' + resp.status + '），请检查后端 PHP 是否正常', 'err');
      throw new Error('bad_response_' + resp.status);
    }
    if (data && data.code === 401) {
      // 登录失效统一踢回登录页
      App.user = null;
      App.boot = null;
      showLogin();
      toast('登录已失效，请重新登录', 'warn');
      throw new Error('unauthorized');
    }
    if (data && data.code === 419) {
      // CSRF token 失效：清空并提示用户刷新
      window._csrfToken = '';
      toast('会话凭证已过期，正在为您刷新…', 'warn');
      await fetchCsrf();
      throw new Error('csrf_retry');
    }
    return data;
  }
  App.api = api;

  // 便捷方法
  const GET = (p, q) => {
    if (q) {
      const sp = new URLSearchParams();
      Object.keys(q).forEach(k => { if (q[k] !== '' && q[k] != null) sp.set(k, q[k]); });
      const qs = sp.toString();
      if (qs) p += (p.indexOf('?') >= 0 ? '&' : '?') + qs;
    }
    return api('GET', p);
  };
  const POST = (p, body) => api('POST', p, body || {});
  App.GET = GET; App.POST = POST;

  /** 文件上传（multipart/form-data），自动携带 CSRF token；file 为 Input 的 File 对象 */
  App.upload = async function (path, file, extra) {
    const t = await fetchCsrf();
    const fd = new FormData();
    fd.append('file', file);
    if (extra) Object.keys(extra).forEach(k => fd.append(k, extra[k]));
    const headers = {};
    if (t) headers['X-CSRF-Token'] = t;
    let resp;
    try {
      resp = await fetch(path, { method: 'POST', credentials: 'same-origin', headers: headers, body: fd });
    } catch (e) {
      toast('网络请求失败，请确认服务已启动', 'err');
      throw e;
    }
    let data = null;
    const ct = resp.headers.get('content-type') || '';
    if (ct.indexOf('json') >= 0) {
      try { data = await resp.json(); } catch (e) { data = null; }
    }
    if (data === null) {
      toast('服务异常（HTTP ' + resp.status + '），请检查后端 PHP', 'err');
      throw new Error('bad_response_' + resp.status);
    }
    if (data.code === 401) {
      App.user = null; App.boot = null; showLogin();
      toast('登录已失效，请重新登录', 'warn');
      throw new Error('unauthorized');
    }
    return data;
  };

  // 下载类型接口
  App.exportUrl = (path) => path; // Cookie 同源直接可下载

  // =====================================================================
  // 3. 登录 / 会话恢复
  // =====================================================================
  function restartAuthAnim(viewId) {
    const card = document.querySelector('#' + viewId + ' .auth-card');
    if (!card) return;
    card.style.animation = 'none';
    void card.offsetWidth; // 触发重排以重放进场动画
    card.style.animation = '';
  }
  function showLogin() {
    $('#loginView').style.display = 'flex';
    $('#registerView').style.display = 'none';
    $('#appView').style.display = 'none';
    document.body.classList.add('login-page');
    clearAuthErrs();
    restartAuthAnim('loginView');
  }
  function showRegister() {
    $('#loginView').style.display = 'none';
    $('#registerView').style.display = 'flex';
    $('#appView').style.display = 'none';
    document.body.classList.add('login-page');
    clearAuthErrs();
    restartAuthAnim('registerView');
  }
  function showApp() {
    $('#loginView').style.display = 'none';
    $('#registerView').style.display = 'none';
    $('#appView').style.display = 'block';
    document.body.classList.remove('login-page');
    const fab = $('#fabQuick');
    if (fab) fab.classList.remove('hidden');
  }

  // ---------- 登录/注册表单辅助 ----------
  function fieldErr(inputId, errId, msg) {
    const e = $('#' + errId);
    if (e) { e.textContent = msg; e.classList.add('show'); }
    const i = $('#' + inputId);
    if (i) { i.classList.add('is-invalid'); i.focus(); }
  }
  function clearAuthErrs() {
    document.querySelectorAll('.field-err').forEach(e => { e.classList.remove('show'); e.textContent = ''; });
    document.querySelectorAll('.auth-card .form-control').forEach(i => i.classList.remove('is-invalid'));
  }
  function setBtnLoading(btn, on) {
    if (!btn) return;
    btn.disabled = on;
    if (on) {
      btn.dataset.oh = btn.innerHTML;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>请稍候…';
    } else if (btn.dataset.oh) {
      btn.innerHTML = btn.dataset.oh;
    }
  }
  App.togglePwd = function (id, btn) {
    const i = $('#' + id);
    if (!i) return;
    const show = i.type === 'password';
    i.type = show ? 'text' : 'password';
    const icon = btn.querySelector('i');
    if (icon) icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
    i.focus();
  };
  App.capsTip = function (ev, id) {
    const t = $('#' + id);
    if (!t || !ev.getModifierState) return;
    t.classList.toggle('show', !!ev.getModifierState('CapsLock'));
  };
  App.pwdStrength = function () {
    const v = $('#rgPwd').value, el = $('#rgPwdStr');
    if (!el) return;
    if (!v) { el.textContent = ''; return; }
    let s = 0;
    if (v.length >= 8) s++;
    if (v.length >= 12) s++;
    if (/[a-z]/.test(v) && /[A-Z]/.test(v)) s++;
    if (/\d/.test(v)) s++;
    if (/[^A-Za-z0-9]/.test(v)) s++;
    const lv = s <= 2 ? ['弱', '#dc2626'] : (s <= 3 ? ['中', '#b45309'] : ['强', '#059669']);
    el.innerHTML = '密码强度：<b style="color:' + lv[1] + '">' + lv[0] + '</b>';
  };

  App.login = async function (ev) {
    if (ev && ev.preventDefault) ev.preventDefault();
    clearAuthErrs();
    const username = $('#lgUser').value.trim();
    const password = $('#lgPwd').value;
    let bad = false;
    if (!username) { fieldErr('lgUser', 'lgUserErr', '请输入账号'); bad = true; }
    if (!password) { fieldErr('lgPwd', 'lgPwdErr', '请输入密码'); bad = true; }
    if (bad) return false;
    const btn = $('#lgBtn'); setBtnLoading(btn, true);
    try {
      const res = await POST('/api/auth/login', { username, password });
      if (res.code !== 0) {
        fieldErr('lgPwd', 'lgPwdErr', res.msg || '登录失败');
        setBtnLoading(btn, false);
        return false;
      }
      App.user = res.data.user;
      App.boot = res.data.boot;
      enterApp();
    } catch (e) {
      setBtnLoading(btn, false);
      if (e && e.message && e.message.indexOf('bad_response') !== 0 && !e.handled) {
        toast('登录失败：' + (e.message || '未知错误'), 'err');
      }
    }
    return false;
  };

  async function restoreSession() {
    // 刷新页面时恢复会话
    try {
      const res = await GET('/api/auth/me');
      if (res && res.code === 0) {
        App.user = res.data.user;
        App.boot = res.data.boot;
        enterApp();
        return;
      }
    } catch (e) { /* ignore */ }
    showLogin();
  }

  App.logout = async function () {
    try { await POST('/api/auth/logout'); } catch (e) { /* ignore */ }
    App.user = null; App.boot = null;
    showLogin();
    toast('已退出登录');
  };

  App.showRegister = showRegister;

  App.register = async function (ev) {
    if (ev && ev.preventDefault) ev.preventDefault();
    clearAuthErrs();
    const username = $('#rgUser').value.trim();
    const password = $('#rgPwd').value;
    const name     = $('#rgName').value.trim();
    // 客户端预检，规则与后端 Auth::register 一致，错误定位到字段
    let bad = false;
    if (!/^[A-Za-z0-9_]{3,32}$/.test(username)) {
      fieldErr('rgUser', 'rgUserErr', '账号只能包含字母、数字和下划线，长度 3-32 位'); bad = true;
    }
    if (password.length < 8 || password.length > 72) {
      fieldErr('rgPwd', 'rgPwdErr', '密码长度须为 8-72 位'); bad = true;
    }
    if (name.length < 2 || name.length > 32) {
      fieldErr('rgName', 'rgNameErr', '真实姓名长度须为 2-32 个字符'); bad = true;
    }
    if (bad) return false;
    const btn = $('#rgBtn'); setBtnLoading(btn, true);
    try {
      const res = await POST('/api/auth/register', { username, password, name });
      if (res.code !== 0) {
        // 服务端错误按关键词定位到字段，无法定位再 toast
        const msg = res.msg || '注册失败';
        if (msg.indexOf('账号') >= 0) fieldErr('rgUser', 'rgUserErr', msg);
        else if (msg.indexOf('密码') >= 0) fieldErr('rgPwd', 'rgPwdErr', msg);
        else if (msg.indexOf('姓名') >= 0) fieldErr('rgName', 'rgNameErr', msg);
        else toast(msg, 'err');
        setBtnLoading(btn, false);
        return false;
      }
      // 成功：清空表单 + 预填登录账号 + 切回登录页
      $('#rgUser').value = ''; $('#rgPwd').value = ''; $('#rgName').value = '';
      App.pwdStrength();
      setBtnLoading(btn, false);
      toast('注册成功，请等待管理员启用', 'ok');
      showLogin();
      $('#lgUser').value = username;
      $('#lgPwd').focus();
    } catch (e) { setBtnLoading(btn, false); }
    return false;
  };

  App.toggleSide = function () { $('#sidebar').classList.toggle('open'); };

  function enterApp() {
    showApp();
    if (!App.boot) App.boot = { terms: [], enums: { sections:{}, weekdays:{}, types:{}, sources:{} }, courses: [], settings: {} };
    buildNav();
    // 默认页（教师与管理员均从仪表盘进入；管理员看板在校内切换）
    const def = 'dashboard';
    App.page = def;
    App.goPage(def);
  }

  // =====================================================================
  // 4. 枚举与启动数据帮助
  // =====================================================================
  function curTerm() {
    const t = App.boot.terms || [];
    return t.find(x => x.is_current === 1) || t[0] || null;
  }
  App.curTerm = curTerm;
  App.termName = (id) => { const t = (App.boot.terms||[]).find(x => x.id === id); return t ? t.name : ('学期#' + id); };

  // 节次选项（下标即 section 值）
  // 1..6 = 常规两节连排；7..10 = 跨节组合（上午/下午/全天/分组等场景）
  const SEC = {
    1:'1-2节', 2:'3-4节', 3:'5-6节', 4:'7-8节', 5:'9-10节', 6:'11-12节',
    7:'1-4节', 8:'2-4节', 9:'5-8节', 10:'1-8节'
  };
  const MAX_SECTION = 10;
  const WD = { 1:'周一',2:'周二',3:'周三',4:'周四',5:'周五',6:'周六',7:'周日' };
  const TYPES = { normal:'常规课', makeup:'补课', swap:'调课', training:'实训课' };

  function classNames(csv) {
    const all = (App.boot && App.boot.classes) || [];
    const map = new Map(all.map(c => [String(c.id), String(c.name || '')]));
    return String(csv == null ? '' : csv)
      .split(/[,,，、\s]+/)
      .filter(Boolean)
      .map(v => map.get(String(v)) || v)
      .join('、');
  }

  App.enumSection = (i) => SEC[i] || ('节次' + i);
  App.classNames = classNames;
  App.enumWeekday = (i) => WD[i] || '';
  App.enumTypeText = (t) => TYPES[t] || t;
  App.typeBadgeClass = (t) => ({ normal:'bg-primary', makeup:'bg-warning text-dark', swap:'bg-purple', training:'bg-success' }[t] || 'bg-secondary');
  App.enumTypes = TYPES;

  // =====================================================================
  // 课程 ↔ 班级 记忆（每个用户各自维护）
  // 教师录课时自动用最后一次填写的班级，缺省再回落到课程库默认值
  // 存于 localStorage：键 = `ks_cls_pref_${userId}`，值 = { [courseId]: classes }
  // =====================================================================
  function prefKey() { return 'ks_cls_pref_' + (App.user ? App.user.id : '0'); }
  function readClassPref() {
    try { return JSON.parse(localStorage.getItem(prefKey()) || '{}'); } catch (e) { return {}; }
  }
  function writeClassPref(map) { try { localStorage.setItem(prefKey(), JSON.stringify(map)); } catch (e) {} }
  function rememberClassFor(courseId, classes) {
    if (!courseId || !classes) return;
    const m = readClassPref(); m[String(courseId)] = classes; writeClassPref(m);
  }
  // 记下当前录入的"最近一次"课程 id / name（让下次开快速录入能直接选中）
  function rememberLastUsed(courseId, courseName) {
    const m = readClassPref();
    m.__last_course_id = courseId ? String(courseId) : '';
    m.__last_course_name = courseName || '';
    writeClassPref(m);
  }
  function getLastUsed() {
    const m = readClassPref();
    return { id: m.__last_course_id || '', name: m.__last_course_name || '' };
  }
  // 取该课程"实际授课班级"：优先个人记忆 > 课程库默认值
  function resolveClass(courseId, fallback) {
    const pref = readClassPref();
    if (courseId && pref[String(courseId)]) return pref[String(courseId)];
    return fallback || '';
  }
  App._resolveClass = resolveClass;
  App._rememberClass = rememberClassFor;
  App._rememberLastUsed = rememberLastUsed;
  App._getLastUsed = getLastUsed;

  // =====================================================================
  // 5. 导航
  // =====================================================================
  function navItems() {
    const isAdmin = App.user && App.user.role === 'admin';
    if (isAdmin) {
      return [
        { g: '总览' },
        { p: 'dashboard', i: 'speedometer2', t: '全校看板' },
        { p: 'admin-users', i: 'people', t: '用户管理' },
        { p: 'admin-students', i: 'people-fill', t: '学生管理' },
        { p: 'admin-meta', i: 'gear', t: '基础配置' },
        { p: 'admin-aihub', i: 'robot', t: 'AI 中转' },
        { p: 'admin-update', i: 'arrow-repeat', t: '系统更新' },
        { p: 'admin-reconcile', i: 'cash-stack', t: '月度对账' },
        { p: 'logs', i: 'journal-text', t: '操作日志' },
        { g: '教师视角' },
        { p: 'my-lessons', i: 'calendar2-check', t: '我的课时' },
        { p: 'my-calendar', i: 'calendar3', t: '我的日历' },
        { p: 'my-stats', i: 'bar-chart', t: '我的统计' },
        { p: 'ai', i: 'robot', t: 'AI 助手' },
        { p: 'ai-playground', i: 'joystick', t: 'AI 操练场' },
        { p: 'notice', i: 'megaphone', t: '通知公告' },
        { p: 'ai-tokens', i: 'key', t: 'API 令牌' },
        { p: 'settings', i: 'person-gear', t: '个人设置' },
      ];
    }
    return [
      { g: '工作台' },
      { p: 'dashboard', i: 'speedometer2', t: '首页仪表盘' },
      { p: 'my-lessons', i: 'calendar2-check', t: '课时记录' },
      { p: 'my-courses', i: 'book', t: '我的课程' },
      { p: 'my-calendar', i: 'calendar3', t: '课表日历' },
      { p: 'my-stats', i: 'bar-chart', t: '我的统计' },
      { p: 'ai', i: 'robot', t: 'AI 智能分析' },
      { p: 'ai-playground', i: 'joystick', t: 'AI 操练场' },
      { p: 'notice', i: 'megaphone', t: '通知公告' },
      { p: 'ai-tokens', i: 'key', t: 'API 令牌' },
      { p: 'settings', i: 'person-gear', t: '个人设置' },
    ];
  }

  /** 导航右侧小红点：未读公告数（数据来自 Boot 注入的 notice_unread） */
  function navBadge(page) {
    if (page !== 'notice') return '';
    const n = App.boot ? (App.boot.notice_unread || 0) : 0;
    if (!n) return '';
    return '<span class="badge rounded-pill bg-danger ms-auto" style="font-size:10px;line-height:1.5">'
      + (n > 99 ? '99+' : n) + '</span>';
  }

  function buildNav() {
    const host = $('#navList');
    const items = navItems();
    let html = '';
    items.forEach(it => {
      if (it.g) html += '<div class="nav-cap">' + esc(it.g) + '</div>';
      else html += '<div class="nav-item" data-page="' + it.p + '" onclick="KS.goPage(\'' + it.p + '\')">'
        + '<i class="bi bi-' + it.i + '"></i><span>' + esc(it.t) + '</span>'
        + navBadge(it.p) + '</div>';
    });
    host.innerHTML = html;
    setUserBadge();
  }

  function setUserBadge() {
    if (!App.user) return;
    const nm = App.user.name || App.user.username || '?';
    $('#sideAvatar').textContent = nm.charAt(0);
    $('#sideName').textContent = nm;
    $('#sideRole').textContent = (App.user.position || '') + (App.user.department ? ' · ' + App.user.department : '');
  }

  // 顶部标题
  const PAGE_META = {
    dashboard: { t: '首页仪表盘', a: '个人课时工作台' },
    'admin-dashboard': { t: '全校数据看板', a: '管理员' },
    'admin-users': { t: '用户管理', a: '管理员' },
    'admin-students': { t: '学生管理', a: '管理员' },
    'admin-meta': { t: '基础配置', a: '管理员' },
    'admin-aihub': { t: 'AI 中转管理', a: '渠道 · 模型倍率 · 额度分配' },
    'admin-update': { t: '系统更新', a: '在线升级 · 备份 · 回滚' },
    'admin-reconcile': { t: '月度课酬对账', a: '教师 × 月份 交叉对账' },
    'logs': { t: '操作日志', a: '管理员' },
    'my-lessons': { t: '课时记录', a: '查看 · 录入 · 管理' },
    'my-courses': { t: '我的课程', a: '课程库 · 单价 · 收藏' },
    'my-calendar': { t: '课表日历', a: '周视图' },
    'my-stats': { t: '我的统计', a: '数据分析' },
    'ai': { t: 'AI 智能分析', a: '对话 · 解析 · 评估' },
    'ai-playground': { t: 'AI 操练场', a: '选模型 · 调参数 · 看消耗' },
    'notice': { t: '通知公告', a: '查看 · 发布 · 已读回执' },
    'ai-tokens': { t: '我的 API 令牌', a: '额度 · sk- 密钥 · 外部调用' },
    'settings': { t: '个人设置', a: '资料与密码' },
  };

  // =====================================================================
  // 6. 路由：切换页面
  // =====================================================================
  App.goPage = function (p) {
    if (!App.user) return;
    // 权限守卫：教师不能进入管理页
    if (App.user.role !== 'admin' && p.indexOf('admin-') === 0) p = 'dashboard';
    App.page = p;
    $$('.nav-item').forEach(el => el.classList.toggle('active', el.dataset.page === p));
    const meta = PAGE_META[p] || { t: p, a: '' };
    $('#topTitle').textContent = meta.t;
    $('#topCrumb').textContent = meta.a;
    const term = curTerm();
    $('#topTerm').textContent = term ? term.name : '';
    $('#sidebar').classList.remove('open');
    destroyCharts();
    renderPage(p);
  };

  function destroyCharts() {
    Object.keys(App.charts).forEach(k => { try { App.charts[k].destroy(); } catch (e) {} });
    App.charts = {};
  }
  function mountChart(id, cfg) {
    // 延迟到 DOM 渲染后
    requestAnimationFrame(() => {
      const el = document.getElementById(id);
      if (!el) return;
      try {
        if (window.Chart) {
          if (App.charts[id]) { App.charts[id].destroy(); }
          App.charts[id] = new Chart(el, cfg);
        }
      } catch (e) { /* console.warn(e) */ }
    });
  }

  const ROUTERS = {};
  let _pgSeq = 0;
  // 当前页面渲染令牌：renderPage 每次调用递增。异步加载器 await 后若令牌变了，
  // 说明页面已被切走，应中止写 DOM，避免把数据写进错误/不存在的页面（竞态）。
  function pageToken() {
    const h = document.getElementById('pageHost');
    return h ? h.dataset.pg : '';
  }
  function samePage(tok) { return tok !== '' && tok === pageToken(); }
  // 页面级元素是否已随导航被移除（用于异步加载器 await 后的防御）
  function gone(id) { return !document.getElementById(id); }
  App._pageToken = pageToken;
  function renderPage(p) {
    const host = $('#pageHost');
    _pgSeq++;
    host.dataset.pg = _pgSeq;
    const myTok = String(_pgSeq);
    const fn = ROUTERS[p];
    host.innerHTML = '<div class="text-secondary py-5 text-center"><i class="bi bi-hourglass-split me-1"></i>加载中…</div>';
    if (!fn) { host.innerHTML = '<div class="dk-empty"><i class="bi bi-file-earmark-x"></i>未知页面</div>'; return; }
    try {
      const rendered = fn(host);
    } catch (e) {
      if (samePage(myTok)) host.innerHTML = '<div class="dk-empty"><i class="bi bi-exclamation-triangle"></i>页面渲染出错：' + esc(e && e.message) + '</div>';
    }
  }

  // =====================================================================
  // 7. 课时数据公共逻辑（新增/列表读取）
  // =====================================================================
  function   buildLessonQuery() {
    const el = $('#my-term'); if (!el) return null;
    const q = { term_id: el.value || '' };
    const typeEl = $('#my-type');     if (typeEl && typeEl.value)     q.type     = typeEl.value;
    const kwEl   = $('#my-kw');        if (kwEl && kwEl.value.trim()) q.keyword  = kwEl.value.trim();
    const mEl    = $('#my-month');      if (mEl && mEl.value)          q.month    = mEl.value;
    const cEl    = $('#my-course');     if (cEl && cEl.value)          q.course_id = cEl.value;
    const wEl    = $('#my-week');       if (wEl && wEl.value)          q.week     = wEl.value;
    const wdEl   = $('#my-weekday');    if (wdEl && wdEl.value)        q.weekday  = wdEl.value;
    const secEl  = $('#my-section');    if (secEl && secEl.value)      q.section  = secEl.value;
    const sdEl   = $('#my-start');      if (sdEl && sdEl.value)        q.start_date = sdEl.value;
    const edEl   = $('#my-end');        if (edEl && edEl.value)        q.end_date   = edEl.value;
    return q;
  }

  // 一键清空所有筛选，回到"当前学期"全集
  App.resetLessonFilter = function () {
    ['#my-course','#my-type','#my-week','#my-weekday','#my-section','#my-month','#my-start','#my-end','#my-kw'].forEach(s => {
      const e = $(s); if (e) e.value = '';
    });
    KS.refreshLessons();
  };

  function lessonFilterBar(extra) {
    const t = App.boot.terms || [];
    const termOpts = t.map(x => '<option value="' + x.id + '"' + (x.is_current===1?' selected':'') + '>' + esc(x.name) + '</option>').join('');
    const typeOpts = '<option value="">全部类型</option>'
      + Object.keys(TYPES).map(k => '<option value="' + k + '">' + TYPES[k] + '</option>').join('');
    const courses = App.boot.courses || [];
    const cOpts = '<option value="">全部课程</option>' + courses.map(c => '<option value="' + c.id + '">' + esc(c.name) + '</option>').join('');
    const weekOpts  = '<option value="">全部周次</option>' + [1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20].map(w => '<option value="' + w + '">第' + w + '周</option>').join('');
    const weekdayOpts = '<option value="">全部星期</option>'
      + [1,2,3,4,5,6,7].map(k => '<option value="' + k + '">' + App.enumWeekday(k) + '</option>').join('');
    const sectionOpts = '<option value="">全部节次</option>'
      + Array.from({length: MAX_SECTION}, (_, i) => i + 1).map(k => '<option value="' + k + '">' + App.enumSection(k) + '</option>').join('');
    return '<div class="filters" id="myFilters">'
      + '<select id="my-term" class="form-select" style="width:auto;max-width:230px" onchange="KS.refreshLessons()">' + termOpts + '</select>'
      + '<select id="my-course" class="form-select" style="width:auto" onchange="KS.refreshLessons()">' + cOpts + '</select>'
      + '<select id="my-type" class="form-select" style="width:auto" onchange="KS.refreshLessons()">' + typeOpts + '</select>'
      + '<select id="my-week" class="form-select" style="width:auto" onchange="KS.refreshLessons()">' + weekOpts + '</select>'
      + '<select id="my-weekday" class="form-select" style="width:auto" onchange="KS.refreshLessons()">' + weekdayOpts + '</select>'
      + '<select id="my-section" class="form-select" style="width:auto" onchange="KS.refreshLessons()">' + sectionOpts + '</select>'
      + '<input id="my-month" type="month" class="form-control" style="width:auto" onchange="KS.refreshLessons()" title="按月份过滤(YYYY-MM)">'
      + '<input id="my-start" type="date" class="form-control" style="width:auto" onchange="KS.refreshLessons()" title="开始日期">'
      + '<span class="text-muted small">至</span>'
      + '<input id="my-end" type="date" class="form-control" style="width:auto" onchange="KS.refreshLessons()" title="结束日期">'
      + '<input id="my-kw" class="form-control" placeholder="课程/班级/备注搜索" style="width:180px" onkeydown="if(event.key===\'Enter\')KS.refreshLessons()">'
      + '<button class="btn btn-outline-secondary btn-sm" onclick="KS.refreshLessons()"><i class="bi bi-search"></i> 查询</button>'
      + '<button class="btn btn-link btn-sm text-secondary" onclick="KS.resetLessonFilter()" title="重置筛选"><i class="bi bi-arrow-counterclockwise"></i> 重置</button>'
      + '<div class="flex-grow-1"></div>'
      + (extra || '')
      + '</div>';
  }

  // =====================================================================
  // 8. 页面渲染函数（由 ROUTERS 注册）
  // =====================================================================

  // ---------- 仪表盘（教师 + 管理员通用入口，按角色不同内容） ----------
  // 注：实际 ROUTERS['dashboard'] 在文件后部定义（便于教师页渲染后异步补「待处理」条）

  // ============ 教师工作台 ============
  async function renderTeacherDashboard(host) {
    const term = curTerm();
    const _tok = host.dataset.pg;
    host.innerHTML = '<div class="text-secondary py-5 text-center"><i class="bi bi-arrow-repeat me-1"></i>加载工作台…</div>';
    let d;
    try {
      const res = await GET('/api/stat/dashboard', term ? { term_id: term.id } : null);
      if (res.code !== 0) { toast(res.msg, 'err'); return; }
      d = res.data;
    } catch (e) { return; }
    if (!samePage(_tok)) return; // 已切走页面，中止

    const ov = d.overview || {};
    const fav = (App.boot.courses || []).filter(c => c.favorited);
    const recent = d.recent || [];

    // 收藏课程快捷录入按钮（首页下拉）
    const favBtns = fav.length ? fav.map(c =>
      '<button class="btn btn-outline-primary btn-sm" onclick="KS.quickFromCourse(' + c.id + ')">'
      + '<i class="bi bi-star-fill text-warning me-1"></i>' + esc(c.name) + '</button>').join('')
      : '<span class="text-secondary small">暂无收藏，可在课时录入中「收藏」常用课程</span>';

    const weekCnt = (d.by_week || []).length;
    const recentCards = recent.map(l => {
      return '<div class="recent-card" onclick="KS.openEdit(' + l.id + ')">'
        + '<div style="display:flex;justify-content:space-between;gap:8px">'
        + '<b style="font-size:13.5px">' + esc(l.course_name) + '</b>'
        + '<span class="badge ' + App.typeBadgeClass(l.type) + '">' + App.enumTypeText(l.type) + '</span></div>'
        + '<div class="small text-secondary">第' + l.week + '周 ' + (App.enumWeekday(l.weekday)) + ' ' + App.enumSection(l.section)
        + ' · ' + esc(l.classes) + '</div>'
        + '<div style="display:flex;justify-content:space-between;font-size:12.5px"><span class="text-muted">' + l.periods + '节 × ¥' + Number(l.price).toFixed(0) + '</span>'
        + '<span style="color:#059669;font-weight:700">¥' + fmtMoney(l.amount) + '</span></div>'
        + '</div>';
    }).join('') || '<div class="dk-empty small"><i class="bi bi-inbox"></i>还没有课时记录</div>';

    host.innerHTML = ''
      + '<div id="todoStrip"></div>'
      + '<div class="stat-grid mb-3">'
      + statCard('bi-joystick','总课时', d.overview.periods + ' 节', '#2563eb') 
      + statCard('bi-cash-coin','预估课酬', '¥' + fmtMoney(ov.amount), '#059669')
      + statCard('bi-credit-card-2-front','本月课酬', '¥' + fmtMoney((d.month_now||{}).amount || 0), '#d97706')
      + statCard('bi-calendar-check','记录条数', (ov.cnt||0) + ' 条', '#7c3aed')
      + '</div>'

      + '<div class="row g-3 mb-3">'
      + '<div class="col-12 col-xl-7">'
      + '<div class="card h-100"><div class="card-h"><i class="bi bi-star-fill text-warning"></i><span class="tt">常用课程快捷录入</span></div>'
      + '<div class="card-b" style="display:flex;flex-wrap:wrap;gap:8px">' + favBtns
      + '<button class="btn btn-light btn-sm" onclick="KS.goPage(\'my-lessons\')"><i class="bi bi-plus-circle me-1"></i>更多</button>'
      + '</div></div></div>'
      + '<div class="col-12 col-xl-5">'
      + '<div class="card h-100"><div class="card-h"><i class="bi bi-tools"></i><span class="tt">快捷入口</span></div>'
      + '<div class="card-b" style="display:flex;flex-wrap:wrap;gap:8px">'
      + '<button class="btn btn-primary btn-sm" onclick="KS.quickOpen()"><i class="bi bi-lightning-charge me-1"></i>快速录入</button>'
      + '<button class="btn btn-outline-secondary btn-sm" onclick="KS.batchOpen()"><i class="bi bi-collection me-1"></i>批量周次生成</button>'
      + '<button class="btn btn-outline-secondary btn-sm" onclick="KS.goPage(\'ai\')"><i class="bi bi-robot me-1"></i>AI 粘贴课表</button>'
      + '<button class="btn btn-outline-secondary btn-sm" disabled title="预留入口"><i class="bi bi-qr-code me-1"></i>扫码录入(预留)</button>'
      + '</div></div></div>'
      + '</div>'

      + '<div class="chart-row mb-3">'
      + '<div class="card"><div class="card-h"><span class="tt">月度课时（柱状）</span></div><div class="card-b"><div class="chart-box"><canvas id="chk-month"></canvas></div></div></div>'
      + '<div class="card"><div class="card-h"><span class="tt">课酬趋势（折线）</span></div><div class="card-b"><div class="chart-box"><canvas id="chk-trend"></canvas></div></div></div>'
      + '</div>'

      + '<div class="row g-3">'
      + '<div class="col-12 col-xl-6"><div class="card"><div class="card-h"><span class="tt">最近 ' + recent.length + ' 条课时（点击编辑）</span>'
      + '<div class="flex-grow-1"></div><button class="btn btn-link btn-sm" onclick="KS.goPage(\'my-lessons\')">全部</button></div>'
      + '<div class="card-b d-grid gap-2" style="grid-template-columns:repeat(auto-fill,minmax(250px,1fr))">' + recentCards + '</div></div></div>'
      + '<div class="col-12 col-xl-6"><div class="card"><div class="card-h"><span class="tt">上课类型结构</span></div>'
      + '<div class="card-b"><div class="chart-box"><canvas id="chk-donut"></canvas></div></div></div></div>'
      + '</div>';

    // 图表
    const bm = d.by_month || [];
    const months = bm.map(x => x.month), mPeriods = bm.map(x => x.periods), mAmount = bm.map(x => x.amount);
    mountChart('chk-month', { type:'bar', data:{ labels:months, datasets:[{ label:'课时(节)', data:mPeriods, backgroundColor:'#2563eb', borderRadius:6 }] }, options: baseOpts('节') });
    mountChart('chk-trend', { type:'line', data:{ labels:months, datasets:[{ label:'课酬(元)', data:mAmount, borderColor:'#059669', backgroundColor:'rgba(5,150,105,.12)', fill:true, tension:.35, pointRadius:4 }] }, options: baseOpts('元') });
    const bt = ov.by_type || {};
    const donutL = [], donutV = [];
    Object.keys(TYPES).forEach(k => { if (bt[k] && bt[k].periods > 0) { donutL.push(TYPES[k]); donutV.push(bt[k].periods); } });
    mountChart('chk-donut', { type:'doughnut', data:{ labels: donutL, datasets:[{ data: donutV, backgroundColor:['#2563eb','#d97706','#7c3aed','#059669'] }] }, options:{ plugins:{ legend:{ position:'bottom' } } } });
    // AI 快捷入口横幅
    const aiBanner = '<div class="card mt-3" style="border-color:#c7d2fe;background:linear-gradient(135deg,#eef2ff,#f5f3ff)">'
      + '<div class="card-b" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">'
      + '<i class="bi bi-robot text-primary" style="font-size:30px"></i>'
      + '<div style="flex:1;min-width:220px"><div style="font-weight:700;color:#1d4ed8">AI 智能助手</div>'
      + '<div class="small text-secondary">粘贴课表自动建课 · 工作量评估 · 数据问答 · 课时证明生成</div></div>'
      + '<button class="btn btn-primary" onclick="KS.goPage(\'ai\')"><i class="bi bi-chat-dots me-1"></i>打开对话</button></div></div>';
    host.insertAdjacentHTML('beforeend', aiBanner);
  }

  // 「待处理」提醒条：列出未填实际授课日期的课时，供一键补全
  // 这类课时进不了「按月/按授课日期」口径，提醒教师尽快回填。
  async function loadTodoStrip(host) {
    const _tok = host.dataset.pg;
    const term = curTerm();
    let t;
    try {
      const res = await GET('/api/stat/todo', term ? { term_id: term.id } : null);
      if (res.code !== 0) return;
      t = res.data || {};
    } catch (e) { return; }
    if (!samePage(_tok) || gone('todoStrip')) return; // 页面已切走
    const el = $('#todoStrip');
    if (!t.count) { el.innerHTML = ''; return; }
    const list = (t.list || []).slice(0, 3);
    const rows = list.map(l =>
      '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">'
      + '<i class="bi bi-calendar2-x text-warning" style="font-size:16px"></i>'
      + '<span class="small" style="flex:1;min-width:150px">第' + l.week + '周 ' + App.enumWeekday(l.weekday) + ' '
      + App.enumSection(l.section) + ' · <b>' + esc(l.course_name) + '</b>'
      + (l.classes ? ' <span class="text-muted">(' + esc(l.classes) + ')</span>' : '') + '</span>'
      + '<button class="btn btn-sm btn-outline-warning py-0" onclick="KS.openEdit(' + l.id + ')">补填日期</button></div>'
    ).join('');
    const more = t.count > list.length ? '<div class="small text-muted">…还有 ' + (t.count - list.length) + ' 条，见「课时记录」</div>' : '';
    el.innerHTML = '<div class="todo-bar"><div class="hd"><i class="bi bi-exclamation-triangle-fill text-warning"></i>'
      + '<span>待处理：有 <b>' + t.count + '</b> 条课时未填实际授课日期（无法计入月份统计）</span></div>'
      + '<div class="list d-grid gap-1">' + rows + more + '</div>'
      + '<div class="ft"><button class="btn btn-sm btn-primary" onclick="KS.goPage(\'my-lessons\')"><i class="bi bi-list-check me-1"></i>去课时记录处理</button></div></div>';
  }
  // 保存/删除课时后若正停在教师工作台，刷新「待处理」条
  function refreshTodoIfDashboard() {
    if (App.page === 'dashboard' && App.user && App.user.role !== 'admin') {
      const host = $('#pageHost'); if (host) loadTodoStrip(host);
    }
  }
  App._refreshTodoIfDashboard = refreshTodoIfDashboard;
  ROUTERS['dashboard'] = function (host) {
    if (App.user.role === 'admin') return renderAdminDashboard(host);
    // 教师工作台主体先渲染，随后异步补「待处理」条（不阻塞首屏）
    const r = renderTeacherDashboard(host);
    if (r && r.then) r.then(() => loadTodoStrip(host));
    return r;
  };

  function statCard(icon, label, value, color) {
    return '<div class="stat-card"><div class="ic" style="background:' + color + '1a;color:' + color + '"><i class="bi ' + icon + '"></i></div>'
      + '<div style="min-width:0"><div class="n" style="color:' + color + '">' + value + '</div><div class="l">' + label + '</div></div></div>';
  }
  App.statCard = statCard;

  function baseOpts(unit) {
    return { responsive:true, maintainAspectRatio:false, plugins:{ legend:{ display:false } },
      scales:{ y:{ beginAtZero:true, ticks:{ callback: v => v + unit } }, x:{ grid:{ display:false } } } };
  }

  // ============ 管理员看板 ============
  async function renderAdminDashboard(host) {
    const term = curTerm();
    const _tok = host.dataset.pg;
    host.innerHTML = '<div class="text-secondary py-5 text-center"><i class="bi bi-arrow-repeat me-1"></i>加载全校数据…</div>';
    let d;
    try {
      const res = await GET('/api/stat/school', term ? { term_id: term.id } : null);
      if (res.code !== 0) { toast(res.msg, 'err'); return; }
      d = res.data;
    } catch (e) { return; }
    if (!samePage(_tok)) return; // 已切走
    const ov = d.overview || {};
    host.innerHTML = ''
      + '<div class="stat-grid mb-3">'
      + statCard('bi-people','教师总数', ov.active_count + ' / ' + ov.teacher_count, '#2563eb')
      + statCard('bi-calendar-event','全校课时', (ov.periods||0) + ' 节', '#7c3aed')
      + statCard('bi-cash-coin','课酬合计', '¥' + fmtMoney(ov.amount), '#059669')
      + statCard('bi-journal-check','记录条数', (ov.lesson_count||0) + ' 条', '#d97706')
      + '</div>'
      + '<div class="row g-3 mb-3">'
      + '<div class="col-12 col-xl-7"><div class="card"><div class="card-h"><span class="tt">各院系课酬汇总</span>'
      + '<div class="flex-grow-1"></div>'
      + '<button class="btn btn-outline-secondary btn-sm" onclick="KS.exportSchool(\'summary\')"><i class="bi bi-download me-1"></i>导出工资表</button></div>'
      + '<div class="table-responsive"><table class="table mb-0"><thead><tr><th>院系</th><th>教师</th><th>记录</th><th>总课时</th><th class="text-end">课酬(元)</th></tr></thead><tbody>'
      + (d.by_department||[]).map(r => '<tr><td>' + esc(r.department) + '</td><td>' + r.teacher_count + '</td><td>' + r.cnt + '</td><td>' + r.periods + '</td>'
        + '<td class="text-end" style="color:#059669;font-weight:600">' + fmtMoney(r.amount) + '</td></tr>').join('')
      + '</tbody></table></div></div></div>'
      + '<div class="col-12 col-xl-5"><div class="card"><div class="card-h"><span class="tt">教师课酬 Top</span></div>'
      + '<div class="card-b" style="display:flex;flex-direction:column;gap:10px">'
      + (d.by_teacher||[]).slice(0,6).map(t => '<div><div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px">'
        + '<span>' + esc(t.name) + ' <span class="text-muted">(' + esc(t.department) + ')</span></span>'
        + '<span style="font-weight:600">' + t.periods + '节 · ¥' + fmtMoney(t.amount) + '</span></div>'
        + '<div class="progress" style="height:7px"><div class="progress-bar bg-success" style="width:' + pct(t.amount, (d.by_teacher||[])[0] && (d.by_teacher||[])[0].amount) + '%"></div></div></div>').join('')
        + '</div></div></div>'
      + '</div>'
      + '<div class="chart-row">'
      + '<div class="card"><div class="card-h"><span class="tt">全校月度课时趋势</span></div><div class="card-b"><div class="chart-box"><canvas id="chk-sch-month"></canvas></div></div></div>'
      + '<div class="card"><div class="card-h"><span class="tt">上课类型分布</span></div><div class="card-b"><div class="chart-box"><canvas id="chk-sch-type"></canvas></div></div></div>'
      + '</div>'
      + '<div class="card mt-3"><div class="card-h"><i class="bi bi-cash-coin text-success"></i><span class="tt">系统管理快捷入口</span></div>'
      + '<div class="card-b d-flex gap-2 flex-wrap">'
      + '<button class="btn btn-outline-secondary btn-sm" onclick="KS.goPage(\'admin-users\')"><i class="bi bi-people me-1"></i>用户管理</button>'
      + '<button class="btn btn-outline-secondary btn-sm" onclick="KS.goPage(\'admin-meta\')"><i class="bi bi-gear me-1"></i>基础配置</button>'
      + '<button class="btn btn-outline-secondary btn-sm" onclick="KS.exportSchool(\'detail\')"><i class="bi bi-table me-1"></i>导出全校明细</button>'
      + '<button class="btn btn-outline-secondary btn-sm" onclick="KS.exportSchool(\'department\')"><i class="bi bi-diagram-3 me-1"></i>院系汇总</button>'
      + '<button class="btn btn-outline-secondary btn-sm" onclick="KS.backupDB()"><i class="bi bi-database-down me-1"></i>整库 SQL 备份</button>'
      + '</div></div>';

    const bm = d.by_month || [];
    mountChart('chk-sch-month', { type:'bar', data:{ labels: bm.map(x=>x.month), datasets:[{ label:'课时', data: bm.map(x=>x.periods), backgroundColor:'#2563eb', borderRadius:6 },{ label:'课酬', data: bm.map(x=>x.amount), backgroundColor:'#059669', borderRadius:6, type:'line', yAxisID:'y1' }] }, options:{ responsive:true, maintainAspectRatio:false, scales:{ y:{ beginAtZero:true, title:{display:true,text:'节'} }, y1:{ position:'right', beginAtZero:true, grid:{display:false}, title:{display:true,text:'元'} } } } });
    const bt = d.by_type || [];
    mountChart('chk-sch-type', { type:'doughnut', data:{ labels: bt.map(x=>x.text), datasets:[{ data: bt.map(x=>x.periods), backgroundColor:['#2563eb','#d97706','#7c3aed','#059669'] }] }, options:{ plugins:{ legend:{ position:'bottom' } } } });
  }
  function pct(v, max) { if (!max) return 0; return Math.max(3, Math.round(v/max*100)); }

  // ============ 课时记录页 ============
  ROUTERS['my-lessons'] = function (host) {
    host.innerHTML = '<div class="card"><div class="card-h"><i class="bi bi-calendar2-check text-primary"></i><span class="tt">我的课时记录</span>'
      + '<div class="flex-grow-1"></div>'
      + '<button class="btn btn-light btn-sm" onclick="KS.quickOpen()"><i class="bi bi-lightning-charge me-1"></i>快速录入</button>'
      + '<button class="btn btn-light btn-sm" onclick="KS.batchOpen()"><i class="bi bi-collection me-1"></i>批量生成</button>'
      + '<button class="btn btn-outline-primary btn-sm" onclick="KS.importOpen()"><i class="bi bi-upload me-1"></i>导入课表</button>'
      + '<button class="btn btn-primary btn-sm" onclick="KS.lessonForm()"><i class="bi bi-plus-lg me-1"></i>完整新增</button>'
      + '</div>'
      + lessonFilterBar(
          '<button class="btn btn-outline-secondary btn-sm" onclick="KS.lessonBatchByQuery(\'delete\')" title="按当前筛选条件一键删除所有匹配记录"><i class="bi bi-trash me-1"></i>一键删除当前查询</button>'
        + '<button class="btn btn-outline-success btn-sm" onclick="KS.exportMine()"><i class="bi bi-download me-1"></i>导出</button>')
      + '<div class="table-responsive"><table class="table"><thead><tr>'
      + '<th style="width:36px"><input type="checkbox" id="lessonCheckAll" onchange="KS.toggleCheckAll(this)" aria-label="全选当前页"></th>'
      + '<th>周</th><th>星期</th><th>节次</th><th>课程</th><th>班级</th><th>类型</th><th>节数</th><th class="text-end">金额</th><th>来源</th><th>授课日期</th><th style="width:200px">操作</th>'
      + '</tr></thead><tbody id="lessonTbody"></tbody></table></div>'
      + '<div class="card-b d-flex justify-content-between align-items-center pt-2">'
      + '<div class="d-flex align-items-center gap-2">'
      + '<span class="small text-muted" id="lessonSum"></span>'
      + '<span class="vr"></span>'
      + '<span class="small text-muted" id="lessonCheckedInfo">已选 0 条</span>'
      + '<button type="button" class="btn btn-sm btn-outline-secondary" id="lessonCheckAllBtn" onclick="KS.checkAllCurrentPage()" title="一键勾选当前页所有行（再点一次取消）"><i class="bi bi-check2-square me-1"></i>全选当前页</button>'
      + '<button type="button" class="btn btn-sm btn-outline-secondary" id="lessonCheckInvertBtn" onclick="KS.invertCurrentPage()" title="把当前页勾选状态反转"><i class="bi bi-arrow-down-up me-1"></i>反选</button>'
      + '<button class="btn btn-sm btn-outline-danger" id="lessonBatchDelBtn" disabled onclick="KS.lessonBatchDelete(\'ids\')"><i class="bi bi-trash me-1"></i>删除选中</button>'
      + '</div>'
      + '<nav><ul class="pagination pagination-sm mb-0" id="lessonPage"></ul></nav></div>'
      + '</div>';
    loadLessons();
  };

  // -------- 课表导入（CSV / Excel）--------
  let importState = { rows: [], termId: 0 };

  App.importOpen = function () {
    importState = { rows: [], termId: 0 };
    const terms = (App.boot.terms || []);
    const cur = curTerm();
    const termOpts = terms.map(t => '<option value="' + t.id + '"' + (cur && t.id === cur.id ? ' selected' : '') + '>' + esc(t.name) + '</option>').join('');
    if (!termOpts) termOpts = '<option value="">（请先创建学期）</option>';
    const typeOpts = [['normal', '常规课'], ['makeup', '补课'], ['swap', '调课'], ['training', '实训课']]
      .map(x => '<option value="' + x[0] + '">' + x[1] + '</option>').join('');
    const body = ''
      + '<div class="small text-muted mb-2">支持 <b>.csv / .xlsx</b>。表头可用「课程名称 / 班级 / 周次 / 星期 / 节次 / 上课日期 / 课时 / 单价 / 类型 / 备注」，列顺序不限。导入的课时会自动出现在「课表日历」中。</div>'
      + '<div class="row g-2 mb-2">'
      + '  <div class="col-6"><label class="form-label">目标学期</label><select id="impTerm" class="form-select form-select-sm">' + termOpts + '</select></div>'
      + '  <div class="col-3"><label class="form-label">缺省单价</label><input id="impPrice" class="form-control form-control-sm" type="number" min="0" step="1" placeholder="0"></div>'
      + '  <div class="col-3"><label class="form-label">缺省类型</label><select id="impType" class="form-select form-select-sm">' + typeOpts + '</select></div>'
      + '</div>'
      + '<div class="d-flex align-items-center gap-2 mb-2">'
      + '  <input id="impFile" type="file" accept=".csv,.xlsx" class="form-control form-control-sm">'
      + '  <a href="/api/scheduleimport/template" class="btn btn-outline-secondary btn-sm" target="_blank"><i class="bi bi-download me-1"></i>模板</a>'
      + '  <button class="btn btn-primary btn-sm" onclick="KS.importPreview()"><i class="bi bi-search me-1"></i>解析预览</button>'
      + '</div>'
      + '<div id="impPreview"></div>';
    openModal('导入课表', body, [
      { t: '确认导入', c: 'btn-success', act: importConfirm }
    ]);
  };

  App.importPreview = async function () {
    const fileInput = $('#impFile');
    if (!fileInput || !fileInput.files || !fileInput.files.length) { toast('请先选择文件', 'warn'); return; }
    const termSel = $('#impTerm');
    const termId = termSel ? parseInt(termSel.value, 10) : 0;
    if (!termId) { toast('请选择目标学期', 'warn'); return; }
    const box = $('#impPreview');
    if (box) box.innerHTML = '<div class="text-muted small py-2"><i class="bi bi-hourglass-split"></i> 解析中…</div>';
    importState.termId = termId;
    try {
      const res = await App.upload('/api/scheduleimport/preview', fileInput.files[0]);
      if (res.code !== 0) { if (box) box.innerHTML = '<div class="text-danger small">' + esc(res.msg) + '</div>'; return; }
      importState.rows = res.data.rows || [];
      renderImportPreview(res.data);
    } catch (e) {
      if (box) box.innerHTML = '<div class="text-danger small">解析失败：' + esc(e.message || '') + '</div>';
    }
  };

  function renderImportPreview(d) {
    const box = $('#impPreview'); if (!box) return;
    const rows = d.rows || [];
    const stats = d.stats || {};
    if (!rows.length) { box.innerHTML = '<div class="text-muted small py-2">没有可解析的数据行。</div>'; return; }
    let html = '<div class="small text-muted mb-2">共 ' + (stats.total || 0) + ' 行，可导入 ' + (stats.parsed || 0) + ' 行'
      + (stats.invalid ? '，' + stats.invalid + ' 行有错误（将忽略）' : '') + '。请核对无误后点「确认导入」。</div>';
    html += '<div style="max-height:340px;overflow:auto"><table class="table table-sm table-bordered align-middle mb-0"><thead><tr>'
      + '<th>课程</th><th>班级</th><th>周</th><th>星期</th><th>节次</th><th>日期</th><th>课时</th><th>类型</th><th>状态</th></tr></thead><tbody>';
    rows.forEach(function (r) {
      const bad = r.error ? ' class="table-danger"' : '';
      html += '<tr' + bad + '><td>' + esc(r.course_name || '') + '</td><td>' + esc(r.classes || '') + '</td>'
        + '<td>' + (r.week || '-') + '</td><td>' + esc(r.weekday_text || '') + '</td><td>' + esc(r.section_text || '') + '</td>'
        + '<td>' + (r.teach_date || '-') + '</td><td>' + (r.periods || '') + '</td><td>' + esc(r.type || '') + '</td>'
        + '<td>' + (r.error ? '<span class="text-danger small">' + esc(r.error) + '</span>' : '<span class="text-success small">OK</span>') + '</td></tr>';
    });
    html += '</tbody></table></div>';
    box.innerHTML = html;
  }

  App.importConfirm = async function () {
    const rows = (importState.rows || []).filter(function (r) { return !r.error; });
    if (!rows.length) { toast('没有可导入的有效行', 'warn'); return; }
    const termId = importState.termId;
    if (!termId) { toast('请先选择学期并解析', 'warn'); return; }
    const priceEl = $('#impPrice'), typeEl = $('#impType');
    const price = priceEl ? (parseFloat(priceEl.value) || 0) : 0;
    const type = typeEl ? typeEl.value : 'normal';
    try {
      const res = await POST('/api/scheduleimport/import', { term_id: termId, rows: rows, default_price: price, default_type: type, skip_conflict: true });
      if (res.code === 0) {
        const d = res.data || {};
        let msg = '导入成功：新增 ' + (d.created || 0) + ' 条';
        if (d.skipped_conflict && d.skipped_conflict.length) msg += '，跳过 ' + d.skipped_conflict.length + ' 条冲突';
        if (d.skipped_invalid && d.skipped_invalid.length) msg += '，' + d.skipped_invalid.length + ' 条无效';
        toast(msg, (d.created || 0) ? 'ok' : 'warn');
        hideModal();
        if (App.page === 'my-lessons') loadLessons();
        if (typeof calCache !== 'undefined') calCache = {};
      } else {
        toast(res.msg, 'err');
      }
    } catch (e) {
      toast('导入失败：' + (e.message || ''), 'err');
    }
  };

  let lessonPageNo = 1, lessonTotal = 0, lessonRows = [];
  async function loadLessons() {
    const tbody = $('#lessonTbody'); if (!tbody) return;
    const q = buildLessonQuery() || {};
    q.limit = 15; q.page = lessonPageNo;
    tbody.innerHTML = '<tr><td colspan="11" class="text-center text-muted py-4">加载中…</td></tr>';
    try {
      const res = await GET('/api/lesson/index', q);
      if (res.code !== 0) { tbody.innerHTML = '<tr><td colspan="11" class="text-center text-muted py-4">' + esc(res.msg) + '</td></tr>'; return; }
      if (gone('lessonTbody')) return;
      lessonRows = res.data.list || [];
      lessonRows.forEach(l => { if (l.id) lessonById[l.id] = l; }); // 供日历/首页点击编辑回显
      lessonTotal = res.data.total || 0;
      const sum = res.data.summary || {};
      if (lessonRows.length === 0) {
        tbody.innerHTML = '<tr><td colspan="11"><div class="dk-empty"><i class="bi bi-inbox"></i>当前条件下暂无课时记录</div></td></tr>';
      } else {
        tbody.innerHTML = lessonRows.map(l => '<tr>'
          + '<td><input type="checkbox" class="lesson-row-check" value="' + l.id + '" onchange="KS.updateCheckState()"></td>'
          + '<td>第' + l.week + '周</td><td>' + App.enumWeekday(l.weekday) + '</td><td>' + App.enumSection(l.section) + '</td>'
          + '<td><b>' + esc(l.course_name) + '</b>' + (l.teacher_name ? '<div class="small text-muted">' + esc(l.teacher_name) + '</div>' : '') + '</td>'
          + '<td class="small">' + esc(App.classNames(l.classes)) + '</td>'
          + '<td><span class="badge ' + App.typeBadgeClass(l.type) + '">' + App.enumTypeText(l.type) + '</span></td>'
          + '<td>' + l.periods + '</td>'
          + '<td class="text-end" style="font-weight:600;color:#059669">¥' + fmtMoney(l.amount) + '</td>'
          + '<td><span class="tag tag-gray">' + esc(l.source_text || l.source) + '</span></td>'
          + '<td class="small text-muted">' + (l.teach_date || '<i class="bi bi-exclamation-triangle text-warning" title="未填日期"></i>') + '</td>'
          + '<td><button class="btn btn-sm btn-outline-primary py-0" onclick="KS.openEdit(' + l.id + ')">编辑</button> '
          + '<button class="btn btn-sm btn-outline-danger py-0" onclick="KS.delLesson(' + l.id + ',' + jsStr(l.course_name) + ')">删</button></td></tr>').join('');
      }
      const maxPage = Math.max(1, Math.ceil(lessonTotal / 15));
      $('#lessonSum').textContent = '共 ' + lessonTotal + ' 条 · 总课时 ' + (sum.periods||0) + ' 节 · 合计 ¥' + fmtMoney(sum.amount);
      // 分页
      let ph = '';
      const show = (t, n, dis) => '<li class="page-item' + (dis?' disabled':'') + (n===lessonPageNo?' active':'') + '"><a class="page-link" href="#" onclick="return KS.lessonPage(' + n + ')">' + t + '</a></li>';
      ph += show('«', Math.max(1, lessonPageNo-1), lessonPageNo<=1);
      for (let i=1;i<=maxPage;i++) ph += show(i,i,false);
      ph += show('»', Math.min(maxPage, lessonPageNo+1), lessonPageNo>=maxPage);
      $('#lessonPage').innerHTML = ph;
      const ca = $('#lessonCheckAll'); if (ca) { ca.checked = false; ca.indeterminate = false; }
      KS.updateCheckState();
    } catch (e) {}
  }
  App.lessonPage = function (n) { lessonPageNo = n; loadLessons(); return false; };
  App.refreshLessons = function () { lessonPageNo = 1; loadLessons(); };

  // ---------- 我的课时记录：复选框批量选择 / 批量删除 ----------
  /**
   * 刷新 "已选 N 条" + 启用/禁用批量删除按钮 + 同步全选框三态
   * 由 onchange / 翻页 / 重渲后调用
   */
  App.updateCheckState = function () {
    const checks = document.querySelectorAll('#lessonTbody .lesson-row-check');
    const sel = [];
    checks.forEach(c => { if (c.checked) sel.push(c.value); });
    const info = $('#lessonCheckedInfo');
    if (info) info.textContent = '已选 ' + sel.length + ' 条';
    const btn = $('#lessonBatchDelBtn');
    if (btn) btn.disabled = sel.length === 0;
    const ca = $('#lessonCheckAll');
    if (ca) {
      if (checks.length === 0) { ca.checked = false; ca.indeterminate = false; }
      else {
        const checkedCount = sel.length;
        ca.checked = checkedCount === checks.length;
        ca.indeterminate = checkedCount > 0 && checkedCount < checks.length;
      }
    }
    // 同步"全选当前页"按钮文字：全勾上时变成"取消全选"
    const allBtn = $('#lessonCheckAllBtn');
    if (allBtn) {
      const allChecked = checks.length > 0 && Array.from(checks).every(c => c.checked);
      allBtn.innerHTML = allChecked
        ? '<i class="bi bi-x-square me-1"></i>取消全选'
        : '<i class="bi bi-check2-square me-1"></i>全选当前页';
    }
  };

  /**
   * 表头全选框点击：把当前页所有行设为相同 checked
   * 注意：只对"当前页"生效（避免翻页后误选其他页），
   * 后端 batchDelete 会用 ids 全量校验，不在前端的页/全集合里糊弄
   */
  App.toggleCheckAll = function (cb) {
    const checks = document.querySelectorAll('#lessonTbody .lesson-row-check');
    checks.forEach(c => { c.checked = cb.checked; });
    App.updateCheckState();
  };

  /**
   * "全选当前页" 按钮：一键把当前页所有行打勾（再点一次取消全部）
   * 配套更新按钮文字和图标，交互更明显
   */
  App.checkAllCurrentPage = function () {
    const checks = document.querySelectorAll('#lessonTbody .lesson-row-check');
    if (checks.length === 0) { toast('当前页没有可勾选的记录', 'warn'); return; }
    const btn = $('#lessonCheckAllBtn');
    const allChecked = Array.from(checks).every(c => c.checked);
    checks.forEach(c => { c.checked = !allChecked; });
    App.updateCheckState();
    if (btn) {
      btn.innerHTML = allChecked
        ? '<i class="bi bi-check2-square me-1"></i>全选当前页'
        : '<i class="bi bi-x-square me-1"></i>取消全选';
    }
  };

  /**
   * "反选" 按钮：把当前页每行的 checked 状态取反
   */
  App.invertCurrentPage = function () {
    const checks = document.querySelectorAll('#lessonTbody .lesson-row-check');
    if (checks.length === 0) { toast('当前页没有可操作的记录', 'warn'); return; }
    checks.forEach(c => { c.checked = !c.checked; });
    App.updateCheckState();
  };

  /**
   * "删除选中" 按钮：勾选模式批量软删除
   */
  App.lessonBatchDelete = function (mode) {
    if (mode === 'all') return App.lessonBatchByQuery('delete');
    const checks = document.querySelectorAll('#lessonTbody .lesson-row-check:checked');
    if (checks.length === 0) { toast('请先勾选要删除的记录', 'warn'); return; }
    if (checks.length > 500) { toast('单次最多删除 500 条', 'warn'); return; }
    const ids = Array.from(checks).map(c => parseInt(c.value, 10)).filter(n => n > 0);
    if (ids.length === 0) { toast('所选记录 id 无效', 'warn'); return; }
    if (!confirm('确认删除已选 ' + ids.length + ' 条课时记录？\n（软删除，可在「回收站」恢复）')) return;
    (async () => {
      try {
        const res = await POST('/api/lesson/batchDelete', { ids, mode: 'ids', note: '' });
        if (res.code === 0) {
          toast('已删除 ' + (res.data?.deleted ?? ids.length) + ' 条');
          // 清掉全选 + 刷新
          const ca = $('#lessonCheckAll'); if (ca) ca.checked = false;
          loadLessons();
        } else {
          toast(res.msg || '删除失败', 'err');
        }
      } catch (e) {
        toast('网络错误：' + (e.message || e), 'err');
      }
    })();
  };

  /**
   * "一键删除当前查询" 按钮：按当前筛选条件批量删除
   * action='delete' 软删 / action='restore' 恢复
   */
  App.lessonBatchByQuery = function (action) {
    const q = buildLessonQuery() || {};
    if (!q.term_id) { toast('请先选择学期（必填筛选条件，避免误删整库）', 'warn'); return; }
    const filtered = Object.assign({}, q); delete filtered.term_id;
    const extraKeys = Object.keys(filtered);
    if (extraKeys.length === 0) { toast('除学期外必须再加至少 1 个筛选条件（教师/课程/月份/周/星期…），防止误操作全学期', 'warn'); return; }
    const summary = '当前筛选：' + (() => {
      const lbls = [];
      if (q.type)      lbls.push('类型=' + q.type);
      if (q.course_id) lbls.push('课程ID=' + q.course_id);
      if (q.week)      lbls.push('第' + q.week + '周');
      if (q.weekday)   lbls.push('星期' + q.weekday);
      if (q.section)   lbls.push('节次=' + q.section);
      if (q.month)     lbls.push('月份=' + q.month);
      if (q.keyword)   lbls.push('关键词=' + q.keyword);
      if (q.start_date && q.end_date) lbls.push(q.start_date + '~' + q.end_date);
      return lbls.join('，') || '(仅学期)';
    })();
    const verb = action === 'restore' ? '恢复' : '删除';
    if (!confirm('确认' + verb + '当前筛选下的所有课时？\n' + summary + '\n（软' + verb + '，可在「回收站」反悔）')) return;
    const api = action === 'restore' ? '/api/lesson/batchRestore' : '/api/lesson/batchDelete';
    (async () => {
      try {
        const payload = Object.assign({ mode: 'all', note: '' }, q);
        const res = await POST(api, payload);
        if (res.code === 0) {
          toast('已' + verb + ' ' + (res.data?.deleted ?? 0) + ' 条');
          loadLessons();
        } else {
          toast(res.msg || (verb + '失败'), 'err');
        }
      } catch (e) {
        toast('网络错误：' + (e.message || e), 'err');
      }
    })();
  };

  // ============ 我的课程 ============
  ROUTERS['my-courses'] = function (host) {
    host.innerHTML = '<div class="card"><div class="card-h"><i class="bi bi-book text-primary"></i><span class="tt">我的课程</span>'
      + '<div class="flex-grow-1"></div>'
      + '<button class="btn btn-primary btn-sm" onclick="KS.courseForm()"><i class="bi bi-plus-lg me-1"></i>新建个人课程</button>'
      + '</div>'
      + '<div class="card-b small text-secondary mb-2" style="padding-bottom:0">公共课程（管理员维护，全校可见）+ 我的个人课程（仅本人可见，可自定义单价）。点击星标可收藏常用课程，录入时优先出现在下拉中。</div>'
      + '<div class="table-responsive"><table class="table"><thead><tr>'
      + '<th>课程名称</th><th>授课班级</th><th>单价(元/节)</th><th>归属</th><th>引用</th><th style="width:120px">常用</th><th style="width:130px">操作</th>'
      + '</tr></thead><tbody id="courseTbody"></tbody></table></div></div>';
    loadMyCourses();
  };
  async function loadMyCourses() {
    const tb = $('#courseTbody'); if (!tb) return;
    tb.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">加载中…</td></tr>';
    const res = await GET('/api/course/index');
    if (res.code !== 0) { tb.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">' + esc(res.msg) + '</td></tr>'; return; }
    if (gone('courseTbody')) return;
    const list = res.data || [];
    if (!list.length) { tb.innerHTML = '<tr><td colspan="7"><div class="dk-empty"><i class="bi bi-book"></i>暂无可用课程</div></td></tr>'; return; }
    tb.innerHTML = list.map(c => {
      const pub = c.owner === 0;
      const star = c.favorited ? 'bi-star-fill text-warning' : 'bi-star text-muted';
      return '<tr>'
        + '<td><b>' + esc(c.name) + '</b>' + (c.favorited ? ' <i class="bi bi-star-fill text-warning" style="font-size:11px"></i>' : '') + '</td>'
        + '<td class="small">' + esc(c.classes || '—') + '</td>'
        + '<td>¥' + Number(c.price).toFixed(2) + '</td>'
        + '<td>' + (pub ? '<span class="badge bg-secondary-subtle text-secondary">全校公共</span>' : '<span class="badge bg-primary-subtle text-primary">我的课程</span>') + '</td>'
        + '<td class="small text-muted">' + (c.lesson_count != null ? c.lesson_count : '—') + '</td>'
        + '<td><button class="btn btn-sm btn-link p-0" onclick="KS.favCourse(' + c.id + ')"><i class="bi ' + star + '" style="font-size:16px"></i></button></td>'
        + '<td>'
        + (pub ? '<span class="text-muted small">管理员维护</span>' : '<button class="btn btn-sm btn-outline-primary py-0" onclick="KS.courseForm(' + c.id + ',' + jsStr(c.name) + ')">编辑</button> '
          + '<button class="btn btn-sm btn-outline-danger py-0" onclick="KS.delCourse(' + c.id + ',' + jsStr(c.name) + ')">删</button>')
        + '</td></tr>';
    }).join('');
  }
  App.refreshMyCourses = function () { loadMyCourses(); };
  App.courseForm = function (id, name) {
    const c = id ? (App.boot.courses || []).find(x => x.id == id) : null;
    openModal(id ? '编辑个人课程' : '新建个人课程', `
      <div class="mb-2"><label class="form-label">课程名称 <span class="text-danger">*</span></label>
        <input id="coName" class="form-control" value="${c?esc(c.name):(name?esc(name):'')}" placeholder="如：工业机器人综合实训"></div>
      <div class="row g-2">
        <div class="col-7"><label class="form-label">默认授课班级</label><input id="coClasses" class="form-control" value="${c?esc(c.classes):''}" placeholder="多班逗号分隔"></div>
        <div class="col-5"><label class="form-label">课时单价(元/节)</label><input id="coPrice" type="number" step="0.1" min="0" class="form-control" value="${c?c.price:(App.boot.settings&&App.boot.settings.global_price?App.boot.settings.global_price:'')}"></div>
      </div>
      <div class="small text-muted mt-2">个人课程仅本人可见；同名公共课程已存在时建议直接收藏公共课程、改用录入时单价。</div>`,
      [{ t:'取消', c:'btn-light', x:true }, { t:'保存', c:'btn-primary', act: () => KS.courseSave(id || 0) }]);
  };
  App.courseSave = async function (id) {
    const name = $('#coName').value.trim();
    const classes = $('#coClasses').value.trim();
    const price = parseFloat($('#coPrice').value || 0);
    if (!name) { toast('请填写课程名称', 'warn'); return; }
    if (price < 0) { toast('单价不合法', 'warn'); return; }
    const res = await POST('/api/course/save', { id: id || undefined, name, classes, price });
    if (res.code === 0) { toast(res.msg); hideModal(); loadMyCourses(); reloadBootCourses(); }
    else toast(res.msg, 'err');
  };
  App.delCourse = async function (id, name) {
    if (!confirm('删除个人课程「' + name + '」？已有课时引用的课程不能删除。')) return;
    const res = await POST('/api/course/delete', { id });
    if (res.code === 0) { toast('已删除'); loadMyCourses(); reloadBootCourses(); }
    else toast(res.msg, 'err');
  };
  App.favCourse = async function (id) {
    const res = await POST('/api/course/favorite', { course_id: id });
    if (res.code === 0) { toast(res.data && res.data.favorited ? '已加入常用' : '已取消收藏'); reloadBootCourses(); loadMyCourses(); }
    else toast(res.msg, 'err');
  };

  // ============ 课表日历 ============
  ROUTERS['my-calendar'] = function (host) {
    const term = curTerm();
    host.innerHTML = '<div class="card"><div class="cal-head"><div class="tt">我的课表日历</div>'
      + '<select id="calTerm" class="form-select" style="width:auto" onchange="KS.refreshCalendar()">'
      + (App.boot.terms||[]).map(t => '<option value="' + t.id + '"' + (t.is_current===1?' selected':'') + '>' + esc(t.name) + '</option>').join('')
      + '</select>'
      + '<button class="btn btn-outline-secondary btn-sm" onclick="KS.calPrev()"><i class="bi bi-chevron-left"></i></button>'
      + '<span id="calLabel" style="font-weight:600;font-size:14px;min-width:120px;text-align:center"></span>'
      + '<button class="btn btn-outline-secondary btn-sm" onclick="KS.calNext()"><i class="bi bi-chevron-right"></i></button>'
      + '<button class="btn btn-light btn-sm" onclick="KS.calToday()">本周</button>'
      + '<div class="flex-grow-1"></div><button class="btn btn-primary btn-sm" onclick="KS.quickOpen()"><i class="bi bi-plus"></i> 录入</button>'
      + '</div><div class="cal-grid" id="calGrid"></div></div>';
    refreshCalendar();
  };
  App.calWeek = null; // null=本周（按学期开学日推算）
  // 日历按「学期_周」缓存当周课时；后端单页上限 200 条/周足够（每周最多 42 时段），避免整学期 >200 条被截断
  let calCache = {};
  let lessonById = {}; // id→课时对象（课时列表/日历页填入，供 openEdit 直接回显，减少多余请求）
  let _calToken = 0;   // 日历请求序号：切周/切学期后丢弃过期响应
  function calTermRange(){
    const sel = $('#calTerm');
    const termId = sel ? sel.value : ((curTerm() && curTerm().id) || '');
    const term = (App.boot.terms||[]).find(t => t.id == termId) || curTerm();
    return { term: term, min: term ? term.start_week : 1, max: term ? term.end_week : 20 };
  }
  function defaultCalWeek(term, minW, maxW) {
    // 默认显示「本周」（按学期开学日推算）；推算不出则显示第 1 周
    const now = new Date();
    const start = term && term.start_date ? new Date(term.start_date) : null;
    if (start) {
      const diff = Math.floor((now - start) / 86400000);
      const wk = Math.floor(diff / 7) + 1;
      if (wk >= minW && wk <= maxW) return wk;
    }
    return minW;
  }
  async function refreshCalendar() {
    const grid = $('#calGrid'); if (!grid) return;
    const { term, min, max } = calTermRange();
    let w = App.calWeek;
    if (w == null || w < min || w > max) w = defaultCalWeek(term, min, max);
    App.calWeek = w;
    if ($('#calLabel')) $('#calLabel').textContent = '第 ' + w + ' 周';
    grid.innerHTML = '<div class="cal-empty"><i class="bi bi-hourglass-split"></i> 加载中…</div>';
    const myToken = ++_calToken;
    const key = (term ? term.id : '0') + '_' + w;
    let list = calCache[key];
    if (list === undefined) {
      try {
        const res = await GET('/api/lesson/index', { term_id: term ? term.id : '', week: w, limit: 200 });
        if (_calToken !== myToken || gone('calGrid')) return; // 请求过期/页面切走
        list = res.code === 0 ? (res.data.list || []) : [];
        calCache[key] = list;
      } catch (e) {
        if (_calToken !== myToken || gone('calGrid')) return;
        grid.innerHTML = '<div class="cal-empty">加载失败，请重试</div>';
        return;
      }
    }
    if (_calToken !== myToken || gone('calGrid')) return;
    list.forEach(l => { if (l && l.id) lessonById[l.id] = l; });
    let html = '';
    for (let d = 1; d <= 7; d++) {
      const evs = list.filter(l => l.weekday === d);
      let inner = '<div class="w-tag">' + WD[d] + '</div>';
      if (!evs.length) inner += '<div class="cal-empty"></div>';
      else inner += evs.map(ev => '<div class="event ev-' + ev.type + '" title="' + esc(ev.course_name) + ' ' + App.enumSection(ev.section) + ' · ' + fmtMoney(ev.amount) + '元" onclick="KS.openEdit(' + ev.id + ')">'
        + '<b>' + App.enumSection(ev.section) + '</b> ' + esc(ev.course_name) + (ev.type!=='normal'?' · '+App.enumTypeText(ev.type):'') + '</div>').join('');
      html += '<div class="cal-day">' + inner + '</div>';
    }
    grid.innerHTML = html;
  }
  App.refreshCalendar = function () { refreshCalendar(); };
  App.calPrev = function () { const r = calTermRange(); if (App.calWeek == null || App.calWeek <= r.min) App.calWeek = r.min; else App.calWeek--; refreshCalendar(); };
  App.calNext = function () { const r = calTermRange(); if (App.calWeek == null) { App.calWeek = defaultCalWeek(r.term, r.min, r.max); } if (App.calWeek >= r.max) App.calWeek = r.max; else App.calWeek++; refreshCalendar(); };
  App.calToday = function () { App.calWeek = null; refreshCalendar(); };

  // ============ 我的统计 ============
  ROUTERS['my-stats'] = function (host) {
    host.innerHTML = '<div class="card mb-3"><div class="card-h"><i class="bi bi-bar-chart text-primary"></i><span class="tt">工作量 AI 评估</span>'
      + '<div class="flex-grow-1"></div><button class="btn btn-primary btn-sm" onclick="KS.aiQuick(\'evaluate\',\'\')"><i class="bi bi-robot me-1"></i>生成评估报告</button></div>'
      + '<div class="card-b small text-secondary">系统按「周均课时 ÷ 周标准课时」计算饱和度，给出等级与教务处说明文案，可一键跳转 AI 助手。</div></div>'
      + '<div class="card"><div class="card-h"><span class="tt">我的课酬与课时结构</span></div><div class="card-b" id="myStatsBody"><div class="text-center text-muted py-4">加载中…</div></div></div>';
    loadMyStats();
  };
  async function loadMyStats() {
    const term = curTerm();
    const host = $('#myStatsBody'); if (!host) return;
    try {
      const res = await GET('/api/stat/overview', term ? { term_id: term.id } : null);
      if (res.code !== 0) return;
      if (gone('myStatsBody')) return;
      const ov = res.data;
      let html = '<div class="stat-grid mb-3">'
        + statCard('bi-joystick','学期总课时', ov.periods + ' 节', '#2563eb')
        + statCard('bi-cash-coin','学期课酬', '¥' + fmtMoney(ov.amount), '#059669')
        + statCard('bi-journal-check','记录条数', ov.cnt + ' 条', '#7c3aed')
        + statCard('bi-collection','课程数', (ov.by_course||[]).length + ' 门', '#d97706')
        + '</div>';
      html += '<div class="row g-3"><div class="col-12 col-md-6"><h6 class="fw-bold">类型结构</h6><table class="table table-sm">'
        + '<thead><tr><th>类型</th><th>条数</th><th>课时</th><th class="text-end">课酬</th></tr></thead><tbody>';
      Object.keys(TYPES).forEach(k => {
        const x = (ov.by_type||{})[k];
        html += '<tr><td>' + TYPES[k] + '</td><td>' + (x?x.cnt:0) + '</td><td>' + (x?x.periods:0) + '</td><td class="text-end" style="color:#059669;font-weight:600">¥' + fmtMoney(x?x.amount:0) + '</td></tr>';
      });
      html += '</tbody></table></div>'
        + '<div class="col-12 col-md-6"><h6 class="fw-bold">按课程</h6><table class="table table-sm">'
        + '<thead><tr><th>课程</th><th>条数</th><th>课时</th><th class="text-end">课酬</th></tr></thead><tbody>';
      (ov.by_course||[]).forEach(c => {
        html += '<tr><td>' + esc(c.course_name) + '</td><td>' + c.cnt + '</td><td>' + c.periods + '</td><td class="text-end" style="color:#059669;font-weight:600">¥' + fmtMoney(c.amount) + '</td></tr>';
      });
      html += '</tbody></table></div></div>';
      html += '<div class="row g-3 mt-1"><div class="col-12"><button class="btn btn-outline-success btn-sm" onclick="KS.exportMine()"><i class="bi bi-download me-1"></i>导出个人课时明细</button></div></div>';
      host.innerHTML = html;
    } catch (e) {}
  }
  App.refreshMyStats = function(){ loadMyStats(); };

  // ============ 个人设置 ============
  ROUTERS['settings'] = function (host) {
    const u = App.user || {};
    host.innerHTML = '<div class="row g-3">'
      + '<div class="col-12 col-lg-6"><div class="card"><div class="card-h"><i class="bi bi-person text-primary"></i><span class="tt">个人资料</span></div>'
      + '<div class="card-b"><div class="mb-3"><label class="form-label">姓名</label><input id="pfName" class="form-control" value="' + esc(u.name) + '"></div>'
      + '<div class="mb-3"><label class="form-label">岗位</label><input id="pfPos" class="form-control" value="' + esc(u.position||'') + '"></div>'
      + '<div class="mb-3"><label class="form-label">所属院系</label><input class="form-control" value="' + esc(u.department||'—') + '" disabled></div>'
      + '<div class="mb-3"><label class="form-label">登录账号</label><input class="form-control" value="' + esc(u.username) + '" disabled></div>'
      + '<button class="btn btn-primary" onclick="KS.saveProfile()"><i class="bi bi-check me-1"></i>保存资料</button></div></div></div>'
      + '<div class="col-12 col-lg-6"><div class="card"><div class="card-h"><i class="bi bi-shield-lock text-warning"></i><span class="tt">修改登录密码</span></div>'
      + '<div class="card-b"><div class="mb-3"><label class="form-label">原密码</label><input type="password" id="pwOld" class="form-control"></div>'
      + '<div class="mb-3"><label class="form-label">新密码</label><input type="password" id="pwNew" class="form-control" placeholder="至少 6 位"></div>'
      + '<div class="mb-3"><label class="form-label">确认新密码</label><input type="password" id="pwNew2" class="form-control"></div>'
      + '<button class="btn btn-warning" onclick="KS.changePwd()"><i class="bi bi-key me-1"></i>修改密码</button></div></div></div>'
      + '<div class="col-12"><div class="card"><div class="card-h"><i class="bi bi-robot text-success"></i><span class="tt">个人 AI 大模型</span></div>'
      + '<div class="card-b"><div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" id="pfAiEnabled"><label class="form-check-label" for="pfAiEnabled">启用我的 AI 分析</label></div>'
      + '<div class="mb-3"><label class="form-label">模型来源</label><select id="pfAiSource" class="form-select" onchange="KS.togglePersonalAiFields()"><option value="system">使用系统默认模型</option><option value="custom">使用我自己的模型</option></select></div>'
      + '<div id="pfCustomAiFields"><div class="mb-2"><label class="form-label">接口地址</label><input id="pfAiUrl" class="form-control" placeholder="https://api.example.com/v1/chat/completions"></div>'
      + '<div class="row g-2"><div class="col-12 col-lg-6"><label class="form-label">模型名称</label>'
      + '<div class="input-group"><input id="pfAiModel" class="form-control" placeholder="如：gpt-4o-mini" list="pfAiModelList">'
      + '<button class="btn btn-outline-secondary" type="button" onclick="KS.fetchAiModels()" title="根据接口地址和 Key 自动拉取模型列表"><i class="bi bi-arrow-down-circle me-1"></i>拉取</button></div>'
      + '<datalist id="pfAiModelList"></datalist></div>'
      + '<div class="col-12 col-lg-6"><label class="form-label">API Key</label><input id="pfAiKey" type="password" class="form-control" placeholder="留空表示保留原 Key"></div></div></div>'
      + '<div class="small text-muted mt-2" id="pfAiStatus">正在读取配置…</div>'
      + '<button class="btn btn-success mt-3" onclick="KS.savePersonalAi()"><i class="bi bi-check me-1"></i>保存 AI 配置</button></div></div></div>'
      + '</div>';
    loadPersonalAiForm();
  };
  App.saveProfile = async function () {
    const name = $('#pfName').value.trim(), pos = $('#pfPos').value.trim();
    if (!name) { toast('姓名不能为空','warn'); return; }
    const res = await POST('/api/profile/save', { name, position: pos });
    if (res.code === 0) { App.user = res.data.user; setUserBadge(); toast('资料已保存'); }
    else toast(res.msg,'err');
  };
  async function loadPersonalAiForm(){
    const res = await GET('/api/profile/ai');
    if(res.code!==0 || !$('#pfAiEnabled')) return;
    const d=res.data||{};
    $('#pfAiEnabled').checked = Number(d.enabled||0) === 1;
    $('#pfAiSource').value = d.source === 'custom' ? 'custom' : 'system';
    $('#pfAiUrl').value = d.api_url || '';
    $('#pfAiModel').value = d.model || '';
    $('#pfAiKey').value = '';
    $('#pfAiStatus').textContent = d.system_enabled ? (d.effective_configured ? ('当前生效：'+(d.effective_source==='custom'?'个人模型':'系统模型')+'，接口已配置') : '当前来源尚未完成接口、模型或 Key 配置') : '管理员已关闭系统 AI，当前不能调用大模型';
    App.togglePersonalAiFields();
  }
  App.fetchAiModels=async function(){
    const url=$('#pfAiUrl').value.trim(), key=$('#pfAiKey').value.trim();
    if(!url){toast('请先填写接口地址','warn');return;}
    toast('正在拉取模型列表…');
    const res=await POST('/api/profile/aiModels',{api_url:url,api_key:key});
    if(res.code!==0){toast(res.msg,'err');return;}
    const models=(res.data&&res.data.models)||[];
    const dl=$('#pfAiModelList'); if(!dl)return;
    dl.innerHTML=models.map(m=>'<option value="'+esc(m)+'">').join('');
    const inp=$('#pfAiModel');
    if(inp && !inp.value && models.length) inp.value=models[0];
    toast('已拉取 '+models.length+' 个模型，点击模型名称输入框可下拉选择');
    if(inp) inp.focus();
  };
  App.togglePersonalAiFields=function(){
    const custom=$('#pfAiSource') && $('#pfAiSource').value==='custom';
    if($('#pfCustomAiFields')) $('#pfCustomAiFields').style.display=custom?'':'none';
  };
  App.savePersonalAi=async function(){
    const payload={
      enabled:$('#pfAiEnabled').checked?1:0,
      source:$('#pfAiSource').value,
      api_url:$('#pfAiUrl').value.trim(),
      model:$('#pfAiModel').value.trim(),
      api_key:$('#pfAiKey').value.trim()
    };
    const res=await POST('/api/profile/aiSave',payload);
    if(res.code!==0){toast(res.msg,'err');return;}
    if(App.boot && App.boot.settings) App.boot.settings.ai=res.data;
    toast('个人 AI 配置已保存');
    loadPersonalAiForm();
  };
  App.changePwd = async function () {
    const o=$('#pwOld').value, n=$('#pwNew').value, c=$('#pwNew2').value;
    if (!o||!n) { toast('请填写原密码与新密码','warn'); return; }
    if (n.length<6){toast('新密码至少6位','warn');return;}
    if (n!==c){toast('两次新密码不一致','warn');return;}
    const res = await POST('/api/profile/password', { old_password:o, new_password:n, confirm_password:c });
    if (res.code===0){ toast(res.msg); $('#pwOld').value=$('#pwNew').value=$('#pwNew2').value=''; }
    else toast(res.msg,'err');
  };

  // =====================================================================
  // 9. 课时表单（完整新增/编辑）
  // =====================================================================
  async function termWeekRange(termId) {
    const t = (App.boot.terms||[]).find(x => x.id == termId) || curTerm();
    return { min: t?t.start_week:1, max: t?t.end_week:20 };
  }

  // 管理员代录时：教师下拉选项
  async function loadTeacherOptions() {
    try {
      const res = await GET('/api/admin/teachers');
      if (res.code !== 0) return '';
      const list = res.data || [];
      const opts = list.filter(u => u.role === 'teacher' && u.status === 1)
        .map(t => '<option value="' + t.id + '">' + esc(t.name) + '（' + esc(t.username) + '）</option>').join('');
      return '<select id="fAdminTeacher" class="form-select">' + opts + '</select>';
    } catch (e) { return ''; }
  }

  App.lessonForm = async function (lesson) {
    const isAdmin = App.user.role === 'admin';
    const term = curTerm();
    const termId = lesson ? lesson.term_id : (term ? term.id : '');
    // 课程下拉（老师用自己可用课程；管理员可用全校课程，可为指定老师代录）
    // 三组：常用 ★ / 全部课程 / 自定义输入
    const courses = App.boot.courses || [];
    const favOpts = courses.filter(c => c.favorited).map(c => '<option value="fav_'+c.id+'"' + (lesson && lesson.course_id==c.id?' selected':'') + '>★ ' + esc(c.name) + (c.price>0?('（¥'+c.price+'）'):'') + '</option>').join('');
    const allOpts = courses.map(c => '<option value="all_'+c.id+'"' + (lesson && lesson.course_id==c.id?' selected':'') + '>' + esc(c.name) + (c.owner===0?'':' (我的)') + (c.price>0?('（¥'+c.price+'）'):'') + '</option>').join('');
    const cOpts = '<option value="">— 选课程（含收藏★/全部/自定义） —</option>'
      + (favOpts ? '<optgroup label="常用课程">'+favOpts+'</optgroup>' : '')
      + '<optgroup label="全部课程">'+allOpts+'</optgroup>'
      + '<optgroup label="其他"><option value="__custom__">—— 自定义输入 ——</option></optgroup>';
    const teachers = isAdmin ? await loadTeacherOptions() : '';
    // 类型
    const typeOpts = Object.keys(TYPES).map(k => '<option value="' + k + '"' + (lesson && lesson.type==k?' selected':'') + '>' + TYPES[k] + '</option>').join('');
    const open = `<input type="hidden" id="fTermId" value="${termId}">
      <div class="mb-2">
      <label class="form-label">课程名称 <span class="text-danger">*</span></label>
      <div class="d-flex gap-2"><select id="fCourse" class="form-select" onchange="KS.pickCourse(this.value)">${cOpts}</select></div>
      <input id="fCourseName" class="form-control mt-2" placeholder="或直接输入课程名" value="${esc(lesson?lesson.course_name:'')}">
    </div>
    <div class="row g-2">
      <div class="col-6"><label class="form-label">授课班级（多选）</label><div style="position:relative"><div id="fClassesBox" class="class-picker" data-target="fClasses"></div><input id="fClasses" type="hidden"></div></div>
      <div class="col-6"><label class="form-label">课时单价(元/节)</label><input id="fPrice" type="number" step="0.1" min="0" class="form-control" value="${lesson?lesson.price:(App.boot.settings&&App.boot.settings.global_price?App.boot.settings.global_price:'')}"></div>
    </div>
    <div class="row g-2">
      <div class="col-4"><label class="form-label">授课周</label><select id="fWeek" class="form-select" onchange="KS.autoDate()"></select></div>
      <div class="col-4"><label class="form-label">星期</label><select id="fWeekday" class="form-select" onchange="KS.autoDate()"></select></div>
      <div class="col-4"><label class="form-label">节次</label><select id="fSection" class="form-select" onchange="KS.autoPeriods()"></select></div>
    </div>
    <div class="row g-2 mt-0">
      <div class="col-6"><label class="form-label">实际授课日期</label><input id="fDate" type="date" class="form-control" value="${lesson&&lesson.teach_date?lesson.teach_date:''}"></div>
      <div class="col-3"><label class="form-label">上课节数</label><input id="fPeriods" type="number" step="0.5" min="1" max="12" class="form-control" value="${lesson?lesson.periods:2}" oninput="KS.previewAmount()"></div>
      <div class="col-3"><label class="form-label">上课类型</label><select id="fType" class="form-select">${typeOpts}</select></div>
    </div>
    <div class="mb-2"><label class="form-label">备注</label><input id="fRemark" class="form-control" value="${esc(lesson?lesson.remark:'')}"></div>
    <div class="d-flex align-items-center gap-2 text-success fw-bold" id="fAmountPrev" style="font-size:16px"></div>
    ${isAdmin ? (lesson && lesson.id
      ? '<div class="mb-2"><label class="form-label">归属教师（编辑记录归属不可变更）</label><input class="form-control" value="'+esc(lesson.teacher_name || ('#'+lesson.teacher_id))+'" disabled></div>'
      : '<div class="mb-2"><label class="form-label">代录教师（管理员）</label>'+teachers+'</div>')
      : ''}
    `;
    openModal('新增课时', open, [
      { t:'取消', c:'btn-light', x:true },
      { t: lesson ? '保存修改' : '确认新增', c:'btn-primary', act: () => KS.submitLesson(lesson?lesson.id:0) }
    ]);
    // 填枚举
    fillWeekSel($('#fWeek'), termId, lesson ? lesson.week : '');
    fillWeekdaySel($('#fWeekday'), lesson ? lesson.weekday : 1);
    fillSectionSel($('#fSection'), lesson ? lesson.section : 1);
    const r = await termWeekRange(termId); if (lesson && (lesson.week < r.min || lesson.week > r.max)) $('#fWeek').value = r.min;
    App.autoDate(); App.autoPeriods();
    // 初始化班级多选控件（编辑时回填已有班级）
    if (window.classPickerInit) {
      classPickerInit('fClassesBox', lesson ? lesson.classes : '');
    }
    // 预填 amount 预览
    setTimeout(() => App.previewAmount(), 0);
  };

  function fillWeekSel(sel, termId, val) {
    termWeekRange(termId).then(r => {
      sel.innerHTML = '';
      for (let w=r.min; w<=r.max; w++) {
        const o = document.createElement('option'); o.value = w; o.textContent = '第' + w + '周';
        if (w == val) o.selected = true;
        sel.appendChild(o);
      }
      if (!val) { const t=(App.boot.terms||[]).find(x=>x.id==termId)||curTerm(); if (t && t.start_date){ /* 默认当前周? */ } }
    });
  }
  function fillWeekdaySel(sel, val){ sel.innerHTML=''; for(let i=1;i<=7;i++){const o=document.createElement('option');o.value=i;o.textContent=WD[i];if(i==val)o.selected=true;sel.appendChild(o);} }
  function fillSectionSel(sel, val){ sel.innerHTML=''; for(let i=1;i<=MAX_SECTION;i++){const o=document.createElement('option');o.value=i;o.textContent=SEC[i];if(i==val)o.selected=true;sel.appendChild(o);} }
  App.pickCourse = function(raw){
    // raw 形如 fav_<id> / all_<id> / __custom__ / ''
    if (!raw) return;
    if (raw === '__custom__') {
      $('#fCourseName').focus();
      $('#fCourseName').select();
      return;
    }
    const parts = raw.split('_');
    if (parts[0] !== 'fav' && parts[0] !== 'all') return;
    const id = parts[1];
    const c = (App.boot.courses||[]).find(x => String(x.id) === String(id));
    if (c) {
      $('#fCourseName').value = c.name;
      // 班级：优先个人上次填的，没有就用课程库默认
      const def = resolveClass(c.id, c.classes);
      if (window.classPickerSet) classPickerSet('fClassesBox', def);
      else $('#fClasses').value = def;
      $('#fPrice').value = c.price;
      App.previewAmount();
    }
  };
  App.autoPeriods = function(){
    const s = parseInt($('#fSection').value || 1, 10);
    $('#fPeriods').value = 2; // 每节2节为常规；保留可改
    App.autoDate(); App.previewAmount();
  };
  App.autoDate = function(){
    // 依据学期 start_date 推算：week/weekday → date
    const termId = ($('#fTermId') && $('#fTermId').value) || ($('#qTermId') && $('#qTermId').value) || (curTerm() && curTerm().id);
    const term = (App.boot.terms||[]).find(t=>t.id==termId) || curTerm();
    if (!term || !term.start_date) return;
    const w = parseInt(($('#fWeek')||{}).value || 1,10);
    const wd = parseInt(($('#fWeekday')||{}).value || 1,10);
    const base = new Date(term.start_date + 'T00:00:00');
    base.setDate(base.getDate() + (w-1)*7 + (wd-1));
    const y = base.getFullYear(), mo = ('0'+(base.getMonth()+1)).slice(-2), da=('0'+base.getDate()).slice(-2);
    const ds = y + '-' + mo + '-' + da;
    if ($('#fDate')) $('#fDate').value = ds;
    if ($('#qDate')) $('#qDate').value = ds;
  };
  App.previewAmount = function(){
    const p = parseFloat($('#fPeriods') && $('#fPeriods').value || 2);
    const pr = parseFloat($('#fPrice') && $('#fPrice').value || 0);
    const el = $('#fAmountPrev'); if (!el) return;
    const amt = (p*pr||0).toFixed(2);
    el.innerHTML = '<i class="bi bi-calculator me-1"></i>金额：¥' + fmtMoney(amt);
  };
  App.submitLesson = async function (id) {
    const courseName = $('#fCourseName').value.trim();
    if (!courseName) { toast('请填写课程名称','warn'); return; }
    const payload = {
      id: id || undefined,
      term_id: parseInt($('#fTermId') ? $('#fTermId').value : (curTerm() && curTerm().id), 10),
      course_id: (function(){ const v=$('#fCourse').value||''; if(v.startsWith('fav_')||v.startsWith('all_')) return parseInt(v.split('_')[1],10); return 0; })(),
      course_name: courseName,
      classes: $('#fClasses').value.trim(),
      price: parseFloat($('#fPrice').value || 0),
      week: parseInt($('#fWeek').value,10),
      weekday: parseInt($('#fWeekday').value,10),
      section: parseInt($('#fSection').value,10),
      periods: parseFloat($('#fPeriods').value || 2),
      type: $('#fType').value,
      remark: $('#fRemark').value.trim(),
      teach_date: $('#fDate').value || '',
    };
    const adminT = $('#fAdminTeacher'); if (adminT) payload.teacher_id = adminT.value;
    const res = await POST('/api/lesson/save', payload);
    if (res.code === 0) {
      if (payload.course_id && payload.classes) rememberClassFor(payload.course_id, payload.classes);
      rememberLastUsed(payload.course_id, payload.course_name);
      toast(res.msg); hideModal(); calCache = {}; loadLessons(); if (App.page==='my-calendar') refreshCalendar(); App._quickCtx=null; reloadBootCourses(); refreshTodoIfDashboard();
    }
    else toast(res.msg,'err');
  };
  async function findLessonById(id) {
    // 逐页查找（单页上限 200），最多找 10 页（2000 条），找不到返回 null；优先限定当前学期减少扫描量
    const cur = curTerm();
    for (let pg = 1; pg <= 10; pg++) {
      const res = await GET('/api/lesson/index', { limit: 200, page: pg, term_id: cur ? cur.id : '' });
      if (res.code !== 0) return null;
      const rows = res.data.list || [];
      const hit = rows.find(x => x.id === id);
      if (hit) return hit;
      const total = res.data.total || 0;
      if (rows.length === 0 || pg * 200 >= total) return null;
    }
    return null;
  }
  App.openEdit = async function (id) {
    // 从各页已缓存数据里找，找不到再按 id 分页查
    let l = lessonRows.find(x => x.id === id) || lessonById[id];
    if (!l) l = await findLessonById(id);
    if (!l) { toast('未找到该记录','warn'); return; }
    App.lessonForm(l);
  };
  App.delLesson = async function (id, name) {
    if (!confirm('确认删除课时：' + name + '？（软删除，可在操作日志追溯）')) return;
    const res = await POST('/api/lesson/delete', { id });
    if (res.code === 0) { toast('已删除'); calCache={}; loadLessons(); if (App.page==='my-calendar') refreshCalendar(); reloadBootCourses(); refreshTodoIfDashboard(); }
    else toast(res.msg,'err');
  };

  // =====================================================================
  // 10. 极简快速录入弹窗
  // =====================================================================
  App.quickOpen = function () {
    const term = curTerm();
    const courses = App.boot.courses || [];
    const classes = App.boot.classes || [];
    const typeOpts = Object.keys(TYPES).map(k => '<option value="' + k + '">' + TYPES[k] + '</option>').join('');
    // 课程下拉：常用（收藏）+ 全部课程 + 自定义
    const favOpts = courses.filter(c=>c.favorited).map(c => '<option value="fav_'+c.id+'">★ ' + esc(c.name) + '</option>').join('');
    const allOpts = courses.map(c => '<option value="all_'+c.id+'"' + (c.price?(' data-price="'+c.price+'" data-classes="'+esc(c.classes)+'"'):'') + '>' + esc(c.name) + (c.owner===0?'':' (我的)') + '</option>').join('');
    $('#quickBody').innerHTML = `
      <div class="mb-2"><label class="form-label">选择课程</label>
        <select id="qCourse" class="form-select" onchange="KS.qPickCourse(this.value)">
          <option value="">— 选课程（含收藏★/全部/自定义） —</option>
          <optgroup label="常用课程">${favOpts}</optgroup>
          <optgroup label="全部课程">${allOpts}</optgroup>
          <optgroup label="其他"><option value="__custom__">—— 自定义输入 ——</option></optgroup>
        </select>
        <input id="qCourseName" class="form-control mt-2" placeholder="自定义课程名" style="display:none">
      </div>
      <div class="row g-2">
        <div class="col-4"><label class="form-label">班级（多选）</label><div style="position:relative"><div id="qClassesBox" class="class-picker" data-target="qClasses"></div><input id="qClasses" type="hidden"></div></div>
        <div class="col-4"><label class="form-label">单价</label><input id="qPrice" type="number" class="form-control" value="` + (App.boot.settings&&App.boot.settings.global_price?App.boot.settings.global_price:'') + `"></div>
        <div class="col-4"><label class="form-label">节数</label><input id="qPeriods" type="number" value="2" min="1" max="12" class="form-control"></div>
      </div>
      <div class="row g-2 mt-0">
        <div class="col-4"><label class="form-label">授课周</label><select id="qWeek" class="form-select" onchange="KS.autoDate()"></select></div>
        <div class="col-4"><label class="form-label">星期</label><select id="qWeekday" class="form-select" onchange="KS.autoDate()"></select></div>
        <div class="col-4"><label class="form-label">节次</label><select id="qSection" class="form-select"></select></div>
      </div>
      <div class="row g-2 mt-0">
        <div class="col-6"><label class="form-label">授课日期(可留空)</label><input id="qDate" type="date" class="form-control"></div>
        <div class="col-6"><label class="form-label">上课类型</label><select id="qType" class="form-select">` + typeOpts + `</select></div>
      </div>
      <div class="mt-2"><label class="form-label">备注</label><input id="qRemark" class="form-control"></div>`;
    // 预填今天星期
    fillWeekdaySel($('#qWeekday'), todayWeekday());
    fillSectionSel($('#qSection'), 1);
    fillWeekSel($('#qWeek'), term && term.id, '');
    // 智能预填：上次录入的课程直接选中，并按记忆自动填班级
    const last = getLastUsed();
    if (last.id && courses.find(c => c.id == last.id && c.favorited)) {
      $('#qCourse').value = last.id;
      App.qPickCourse(last.id);
    } else if (last.name) {
      // 没收藏但记得名字 → 自动写到课程名输入框，并按名字取记忆
      $('#qCourseName').value = last.name;
      const m = readClassPref();
      const k = '__by_name__' + last.name;
      if (m[k] && window.classPickerSet) classPickerSet('qClassesBox', m[k]);
      else if (m[k]) $('#qClasses').value = m[k];
    }
    // 初始化多选班级控件
    if (window.classPickerInit) classPickerInit('qClassesBox');
    bsModal($('#quickModal')).show();
    // 打开后依据默认周/星期自动填日期
    setTimeout(() => App.autoDate(), 50);
  };
  function todayWeekday(){ const d=new Date().getDay(); return d===0?7:d; }
  App.qPickCourse = function(raw){
    // raw 形如 fav_<id> / all_<id> / __custom__ / ''
    if (!raw) return;
    if (raw === '__custom__') {
      $('#qCourseName').style.display = '';
      $('#qCourseName').value = '';
      $('#qCourseName').focus();
      return;
    }
    $('#qCourseName').style.display = 'none';
    const prefix = raw.split('_')[0];
    const id = raw.split('_')[1];
    const c = (App.boot.courses||[]).find(x=>String(x.id)===String(id));
    if (!c) return;
    $('#qCourseName').value = c.name;
    // 课程默认班级按逗号切，选中并写入 class-picker
    const def = resolveClass(c.id, c.classes);
    if (window.classPickerSet) classPickerSet('qClassesBox', def);
    else $('#qClasses').value = def;
    $('#qPrice').value = c.price;
  };
  App.quickSave = async function () {
    const name = $('#qCourseName').value.trim();
    if (!name) { toast('请填写课程名','warn'); return; }
    const payload = {
      term_id: curTerm() && curTerm().id,
      course_id: (function(){ const v=$('#qCourse').value||''; if(v.startsWith('fav_')||v.startsWith('all_')) return parseInt(v.split('_')[1],10); return 0; })(),
      course_name: name,
      classes: $('#qClasses').value.trim(),
      price: parseFloat($('#qPrice').value||0),
      week: parseInt($('#qWeek').value,10),
      weekday: parseInt($('#qWeekday').value,10),
      section: parseInt($('#qSection').value,10),
      periods: parseFloat($('#qPeriods').value||2),
      type: $('#qType').value,
      teach_date: $('#qDate').value||'',
      remark: ($('#qRemark')&&$('#qRemark').value||'').trim(),
      source: 'manual'
    };
    const res = await POST('/api/lesson/save', payload);
    if (res.code === 0) {
      // 记下该教师该课程常用的班级，下次录入自动填
      if (payload.course_id && payload.classes) rememberClassFor(payload.course_id, payload.classes);
      rememberLastUsed(payload.course_id, payload.course_name);
      toast(res.msg); try{$('#quickModal').__bs.hide();}catch(e){} calCache={}; loadLessons(); reloadBootCourses(); refreshTodoIfDashboard();
    }
    else toast(res.msg,'err');
  };
  App.quickMore = function () { try{$('#quickModal').__bs.hide();}catch(e){} setTimeout(()=>App.lessonForm(),80); };
  App.quickFromCourse = function (id) { // 收藏课程一键带出
    const c = (App.boot.courses||[]).find(x=>x.id===id); if (!c) return;
    App.quickOpen();
    setTimeout(()=>{ $('#qCourse').value=id; App.qPickCourse(id); },60);
  };
  App.quickFromTemplate = function(tpl){ App.quickOpen(); setTimeout(()=>{ App.qPickCourseByObj(tpl); },60); };
  App.qPickCourseByObj = function(o){
    $('#qCourseName').value = o.course_name;
    // 这里没有 course id，仍用课程名做兜底记忆键
    const m = readClassPref();
    const key = '__by_name__' + o.course_name;
    $('#qClasses').value = m[key] || o.classes || '';
    $('#qPrice').value = o.price;
    $('#qPeriods').value = o.periods;
  };

  // =====================================================================
  // 11. 批量周次生成
  // =====================================================================
  App.batchOpen = async function () {
    const term = curTerm();
    const r = term ? await termWeekRange(term.id) : {min:1,max:20};
    const courses = App.boot.courses || [];
    const favOpts = courses.filter(c => c.favorited).map(c => '<option value="fav_'+c.id+'" data-price="' + c.price + '" data-classes="' + esc(c.classes) + '">★ ' + esc(c.name) + '</option>').join('');
    const allOpts = courses.map(c => '<option value="all_'+c.id+'" data-price="' + c.price + '" data-classes="' + esc(c.classes) + '">' + esc(c.name) + (c.owner===0?'':' (我的)') + '</option>').join('');
    const cOpts = '<option value="">— 选课程（含收藏★/全部/自定义） —</option>'
      + (favOpts ? '<optgroup label="常用课程">'+favOpts+'</optgroup>' : '')
      + '<optgroup label="全部课程">'+allOpts+'</optgroup>'
      + '<optgroup label="其他"><option value="__custom__">—— 自定义输入 ——</option></optgroup>';
    openModal('批量周次生成', `
      <div class="mb-2"><label class="form-label">课程</label>
        <select id="bCourse" class="form-select" onchange="KS.bPickCourse(this.value)">${cOpts}</select>
        <input id="bCourseName" class="form-control mt-2" placeholder="课程名（手输）">
      </div>
      <div class="row g-2">
        <div class="col-6"><label class="form-label">班级（多选）</label><div style="position:relative"><div id="bClassesBox" class="class-picker" data-target="bClasses"></div><input id="bClasses" type="hidden"></div></div>
        <div class="col-6"><label class="form-label">单价</label><input id="bPrice" type="number" class="form-control" value="${App.boot.settings&&App.boot.settings.global_price?App.boot.settings.global_price:''}"></div>
      </div>
      <div class="row g-2">
        <div class="col-6"><label class="form-label">起始周</label><select id="bStart" class="form-select"></select></div>
        <div class="col-6"><label class="form-label">结束周</label><select id="bEnd" class="form-select"></select></div>
      </div>
      <div class="row g-2">
        <div class="col-4"><label class="form-label">星期</label><select id="bWeekday" class="form-select"></select></div>
        <div class="col-4"><label class="form-label">节次</label><select id="bSection" class="form-select"></select></div>
        <div class="col-4"><label class="form-label">节数</label><input id="bPeriods" type="number" value="2" min="1" max="12" class="form-control"></div>
      </div>
      <div class="mb-1"><label class="form-label">上课类型</label>
        <select id="bType" class="form-select">` + Object.keys(TYPES).map(k=>'<option value="'+k+'">'+TYPES[k]+'</option>').join('') + `</select></div>
      <div class="form-check"><input class="form-check-input" type="checkbox" id="bStep2"><label class="form-check-label" for="bStep2">隔周生成（单双周）</label></div>
      <div class="small text-muted mt-1">已存在「同周+星期+节次」的时段会自动跳过，不会覆盖。</div>
      <div id="bPrev" class="small text-secondary mt-2"></div>`,
      [ {t:'取消',c:'btn-light',x:true}, {t:'批量生成',c:'btn-primary',act:()=>KS.batchSubmit()} ]);
    fillWeekdaySel($('#bWeekday'), 1); fillSectionSel($('#bSection'), 1);
    const sw=$('#bStart'), ew=$('#bEnd');
    sw.innerHTML='';ew.innerHTML='';
    for(let i=r.min;i<=r.max;i++){sw.innerHTML+='<option value="'+i+'">第'+i+'周</option>';ew.innerHTML+='<option value="'+i+'">第'+i+'周</option>';}
    ew.value = Math.min(r.max, r.min+15);
    const mid=Math.floor((r.min+r.max)/2); if(mid>=r.min&&mid<=r.max) sw.value=mid;
    previewBatch();
    $('#bStart').onchange=previewBatch; $('#bEnd').onchange=previewBatch;
    if (window.classPickerInit) classPickerInit('bClassesBox');
  };
  function previewBatch(){ const s=$('#bStart'),e=$('#bEnd'); if(s&&e){const n=Math.max(0,(parseInt(e.value)-parseInt(s.value)+1)); const p=$('#bPrev'); if(p) p.textContent = (n?('将生成约 '+n+' 条课时记录（含已有时段自动跳过）'):'');} }
  App.bPickCourse = function(raw){
    if (!raw) return;
    if (raw === '__custom__') {
      $('#bCourseName').focus();
      $('#bCourseName').select();
      return;
    }
    const parts = raw.split('_');
    if (parts[0] !== 'fav' && parts[0] !== 'all') return;
    const id = parts[1];
    const c = (App.boot.courses||[]).find(x => String(x.id) === String(id));
    if (!c) return;
    $('#bCourseName').value = c.name;
    const def = resolveClass(c.id, c.classes);
    if (window.classPickerSet) classPickerSet('bClassesBox', def);
    else $('#bClasses').value = def;
    $('#bPrice').value = c.price;
  };
  App.batchSubmit = async function(){
    const name=$('#bCourseName').value.trim(); if(!name){toast('请填写课程名','warn');return;}
    const payload={ term_id: curTerm() && curTerm().id, course_id: (function(){ const v=$('#bCourse').value||''; if(v.startsWith('fav_')||v.startsWith('all_')) return parseInt(v.split('_')[1],10); return 0; })(), course_name: name,
      classes:$('#bClasses').value.trim(), price: parseFloat($('#bPrice').value||0),
      start_week: parseInt($('#bStart').value,10), end_week: parseInt($('#bEnd').value,10),
      weekday: parseInt($('#bWeekday').value,10), section: parseInt($('#bSection').value,10),
      periods: parseFloat($('#bPeriods').value||2), type: $('#bType').value,
      step: $('#bStep2').checked?2:1 };
    const res = await POST('/api/lesson/batch', payload);
    if(res.code===0){
      if (payload.course_id && payload.classes) rememberClassFor(payload.course_id, payload.classes);
      rememberLastUsed(payload.course_id, payload.course_name);
      toast(res.msg); hideModal(); calCache={}; loadLessons(); reloadBootCourses(); refreshTodoIfDashboard();
    }
    else toast(res.msg,'err');
  };

  // =====================================================================
  // 12. 导出 & 备份
  // =====================================================================
  App.exportMine = function(){
    // 带上当前筛选条件（学期/课程/类型/月份/关键词），导出 = 所见即所得
    const q = buildLessonQuery() || {};
    const sp = new URLSearchParams();
    Object.keys(q).forEach(k => { if (q[k] !== '' && q[k] != null) sp.set(k, q[k]); });
    sp.set('t', Date.now());
    downloadCSV('/api/export/mine?' + sp.toString());
    toast('正在按当前筛选条件导出课时明细…');
  };
  App.exportSchool = function(type){
    const term = curTerm(); const q = ['?type='+type];
    if (term) q.push('term_id='+term.id);
    downloadCSV('/api/export/school' + q.join('&') + '&t=' + Date.now());
  };
  App.backupDB = async function(){
    if(!confirm('确认导出整库 SQL 备份？'))return;
    downloadCSV('/api/export/backup?t=' + Date.now());
  };

  // =====================================================================
  // 13. 课程收藏 & 刷新课程
  // =====================================================================
  async function reloadBootCourses(){
    try { const res = await GET('/api/course/index'); if (res.code===0){ App.boot.courses = res.data; } } catch(e){}
  }
  App.toggleFav = async function(id, btn){
    const res = await POST('/api/course/favorite', { course_id: id });
    if(res.code===0){ toast(res.favorited?'已加入常用':'已取消收藏'); reloadBootCourses(); }
    else toast(res.msg,'err');
  };

  // =====================================================================
  // 14. AI 助手
  // =====================================================================
  // -------- 我的 API 令牌（额度 / sk- 密钥 / 外部调用）--------
  // 时间戳 → 'YYYY-MM-DD HH:mm'（本项目其它地方没有同名工具，这里自带一份）
  function fmtTs(ts) {
    const t = Number(ts) || 0;
    if (!t) return '—';
    const d = new Date(t * 1000);
    const p = function (n) { return n < 10 ? '0' + n : '' + n; };
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate())
      + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
  }

  let aiTokState = { tokens: [], quota: null, usage: null, gateway: null };

  ROUTERS['ai-tokens'] = function (host) {
    host.innerHTML = '<div class="card mb-3"><div class="card-h"><i class="bi bi-battery-charging text-success"></i>'
      + '<span class="tt">AI 额度</span></div><div class="card-b" id="aiQuotaBox">'
      + '<div class="text-center text-muted small py-3">加载中…</div></div></div>'
      + '<div class="card mb-3"><div class="card-h"><i class="bi bi-diagram-3 text-primary"></i>'
      + '<span class="tt">接入地址（OpenAI 兼容）</span></div><div class="card-b" id="aiGwBox"></div></div>'
      + '<div class="card"><div class="card-h"><i class="bi bi-key text-warning"></i><span class="tt">我的令牌</span>'
      + '<div class="flex-grow-1"></div>'
      + '<button class="btn btn-sm btn-primary" onclick="KS.aiTokenForm()"><i class="bi bi-plus-lg"></i> 新建令牌</button></div>'
      + '<div class="table-responsive"><table class="table table-sm align-middle"><thead><tr>'
      + '<th>名称</th><th>密钥</th><th>可用模型</th><th>状态</th><th>有效期</th><th>最近使用</th><th style="width:150px">操作</th>'
      + '</tr></thead><tbody id="aiTokenTbody"></tbody></table></div></div>';
    loadAiTokens();
  };

  async function loadAiTokens() {
    const res = await GET('/api/aitoken/mine');
    if (res.code !== 0) { const b = $('#aiQuotaBox'); if (b) b.innerHTML = '<div class="text-danger small">' + esc(res.msg) + '</div>'; return; }
    const d = res.data || {};
    aiTokState.tokens = d.tokens || [];
    aiTokState.quota  = d.quota  || null;
    aiTokState.usage  = d.usage  || null;
    aiTokState.gateway = d.gateway || null;
    renderAiTokens();
  }

  function renderAiTokens() {
    // 额度卡片
    const q = aiTokState.quota, u = aiTokState.usage;
    const box = $('#aiQuotaBox');
    if (box) {
      if (!q) { box.innerHTML = '<div class="text-muted small">暂无额度信息</div>'; }
      else {
        const cell = function (label, used, limit, left) {
          const unlimited = !(limit > 0);
          return '<div class="col-6 col-md-3"><div class="small text-muted">' + label + '</div>'
            + '<div><b>' + esc(String(used)) + '</b>'
            + (unlimited ? ' <span class="text-muted small">/ 不限</span>'
                         : ' <span class="text-muted small">/ ' + esc(String(limit)) + '</span>')
            + '</div>' + (unlimited ? '' : '<div class="small text-muted">剩余 ' + esc(String(left)) + '</div>') + '</div>';
        };
        box.innerHTML = '<div class="row g-3">'
          + '<div class="col-6 col-md-3"><div class="small text-muted">总余额</div><div><b class="fs-5">' + esc(String(q.balance)) + '</b> <span class="text-muted small">点</span></div>'
          + '<div class="small text-muted">累计消耗 ' + esc(String(q.total_used)) + '</div></div>'
          + cell('今日', q.daily_used, q.daily_limit, q.remaining_daily)
          + cell('本周', q.weekly_used, q.weekly_limit, q.remaining_weekly)
          + cell('本月', q.monthly_used, q.monthly_limit, q.remaining_monthly)
          + '</div>'
          + (u ? '<div class="small text-muted mt-2">累计调用 ' + u.count + ' 次（成功 ' + u.success + ' / 失败 ' + u.failed
              + '），消耗 ' + u.points + ' 点，平均耗时 ' + u.avg_latency_ms + ' ms</div>' : '');
      }
    }

    // 接入地址
    const gw = aiTokState.gateway, gb = $('#aiGwBox');
    if (gb) {
      if (!gw) { gb.innerHTML = '<div class="text-muted small">—</div>'; }
      else {
        const line = function (label, url) {
          return '<div class="d-flex align-items-center gap-2 mb-2"><span class="text-muted small" style="width:76px">' + label + '</span>'
            + '<code class="small flex-grow-1" style="word-break:break-all">' + esc(url) + '</code>'
            + '<button class="btn btn-sm btn-outline-secondary" onclick="KS.copyText(\'' + jsStr(url) + '\')">复制</button></div>';
        };
        gb.innerHTML = line('Base URL', gw.base_url) + line('对话接口', gw.chat) + line('模型列表', gw.models)
          + '<div class="small text-muted mt-2">在第三方客户端里：接口类型选 OpenAI，Base URL 填上面的地址，API Key 填下面创建的 <code>sk-</code> 密钥即可。</div>';
      }
    }

    // 令牌列表
    const tb = $('#aiTokenTbody');
    if (!tb) return;
    if (!aiTokState.tokens.length) {
      tb.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">还没有令牌。点击右上角「新建令牌」创建，'
        + '明文密钥只在创建时显示一次。</td></tr>';
      return;
    }
    tb.innerHTML = aiTokState.tokens.map(function (t) {
      const expired = t.expires_at > 0 && t.expires_at <= Math.floor(Date.now() / 1000);
      const st = expired ? '<span class="badge bg-danger">已过期</span>'
        : (t.status ? '<span class="badge bg-success">启用</span>' : '<span class="badge bg-secondary">停用</span>');
      return '<tr>'
        + '<td>' + esc(t.name) + '</td>'
        + '<td><code class="small">' + esc(t.token_prefix) + '••••••••</code></td>'
        + '<td class="small">' + ((t.model_limit && t.model_limit.length) ? esc(t.model_limit.join(', ')) : '<span class="text-muted">不限</span>') + '</td>'
        + '<td>' + st + '</td>'
        + '<td class="small">' + (t.expires_at ? esc(fmtTs(t.expires_at)) : '<span class="text-muted">永久</span>') + '</td>'
        + '<td class="small text-muted">' + (t.last_used_at ? esc(fmtTs(t.last_used_at)) : '未使用') + '</td>'
        + '<td style="white-space:nowrap"><button class="btn btn-sm btn-outline-secondary" onclick="KS.aiTokenToggle(' + t.id + ',' + (t.status ? 0 : 1) + ')">'
        + (t.status ? '停用' : '启用') + '</button> '
        + '<button class="btn btn-sm btn-outline-danger" onclick="KS.aiTokenDel(' + t.id + ')">删</button></td>'
        + '</tr>';
    }).join('');
  }

  App.aiTokenForm = function () {
    openModal('新建 API 令牌', ''
      + '<div class="mb-2"><label class="form-label">令牌名称</label>'
      + '<input id="atkName" class="form-control" placeholder="如 Cherry Studio、实习实训脚本"></div>'
      + '<div class="mb-2"><label class="form-label">可用模型（逗号分隔，留空=不限）</label>'
      + '<input id="atkModels" class="form-control" placeholder="gpt-4o-mini,deepseek-chat"></div>'
      + '<div class="mb-2"><label class="form-label">有效期（留空=永久）</label>'
      + '<input id="atkExp" type="date" class="form-control"></div>'
      + '<div class="small text-muted">创建后明文密钥只显示一次，请立即复制保存。</div>',
      [{ t: '创建', c: 'btn-primary', act: createAiToken }]);
  };

  async function createAiToken() {
    const name = ($('#atkName').value || '').trim();
    if (!name) { toast('请填写令牌名称', 'warn'); return; }
    const models = ($('#atkModels').value || '').split(/[,，\s]+/).filter(Boolean);
    let exp = 0;
    const dv = $('#atkExp').value;
    if (dv) { const ts = Math.floor(new Date(dv + ' 23:59:59').getTime() / 1000); if (ts > 0) exp = ts; }

    const res = await POST('/api/aitoken/create', { name: name, model_limit: models, expires_at: exp });
    if (res.code !== 0) { toast(res.msg, 'err'); return; }

    // 明文只出现这一次：单独弹窗展示，避免关掉后找不到
    const plain = res.data.plain;
    openModal('令牌已创建 · 请立即复制', ''
      + '<div class="alert alert-warning small">这是唯一一次显示完整密钥，关闭后无法再次查看。</div>'
      + '<div class="input-group"><input id="atkPlain" class="form-control font-monospace" value="' + esc(plain) + '" readonly>'
      + '<button class="btn btn-outline-secondary" onclick="KS.copyText(\'' + jsStr(plain) + '\')">复制</button></div>',
      [{ t: '我已保存', c: 'btn-primary', act: function () { hideModal(); loadAiTokens(); } }]);
  }

  App.aiTokenToggle = async function (id, status) {
    const res = await POST('/api/aitoken/toggle', { id: id, status: status });
    if (res.code !== 0) { toast(res.msg, 'err'); return; }
    toast(res.msg); loadAiTokens();
  };

  App.aiTokenDel = async function (id) {
    if (!confirm('删除该令牌？使用该令牌的外部程序将立即失效。')) return;
    const res = await POST('/api/aitoken/delete', { id: id });
    if (res.code !== 0) { toast(res.msg, 'err'); return; }
    toast('已删除'); loadAiTokens();
  };

  App.copyText = function (txt) {
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(txt);
      } else {
        const ta = document.createElement('textarea');
        ta.value = txt; document.body.appendChild(ta); ta.select();
        document.execCommand('copy'); document.body.removeChild(ta);
      }
      toast('已复制');
    } catch (e) { toast('复制失败，请手动选择复制', 'warn'); }
  };

  // -------- AI 操练场（选模型 · 调参数 · 看消耗）--------
  // 会话只存在内存里（刷新即清空），但每次调用都会写入 ks_ai_usage_log 并扣额度。
  let pgState = {
    models: [], presets: [], quota: null,
    model: '', system: '', temperature: 0.7, max_tokens: 0,
    msgs: [], busy: false, hasChannel: false,
  };

  ROUTERS['ai-playground'] = function (host) {
    host.innerHTML = '<div class="ai-wrap">'
      + '<div class="pg-side"><div class="hd"><i class="bi bi-sliders text-primary"></i>参数</div>'
      + '<div class="bd" id="pgParam"><div class="text-center text-muted small py-4">加载中…</div></div></div>'
      + '<div class="ai-main">'
      + '<div class="d-flex align-items-center gap-2 px-3 py-2" style="border-bottom:1px solid #f1f5f9">'
      + '<i class="bi bi-joystick text-primary"></i><span class="small text-muted" id="pgModelTag">—</span>'
      + '<div class="flex-grow-1"></div>'
      + '<button class="btn btn-sm btn-outline-secondary" onclick="KS.pgCopy()"><i class="bi bi-clipboard me-1"></i>复制对话</button> '
      + '<button class="btn btn-sm btn-outline-danger" onclick="KS.pgClear()"><i class="bi bi-eraser me-1"></i>清空</button>'
      + '</div>'
      + '<div class="ai-msgs" id="pgMsgs"></div>'
      + '<div class="ai-in"><textarea id="pgInput" class="form-control" placeholder="输入内容，Enter 发送，Shift+Enter 换行" '
      + 'onkeydown="if(event.key===\'Enter\'&&!event.shiftKey){event.preventDefault();KS.pgSend();}"></textarea>'
      + '<button class="btn btn-primary px-3" id="pgSendBtn" onclick="KS.pgSend()"><i class="bi bi-send-fill"></i></button></div>'
      + '</div></div>';
    pgLoad();
  };

  async function pgLoad() {
    const res = await GET('/api/playground/bootstrap');
    if (gone('pgParam')) return;
    const box = $('#pgParam');
    if (res.code !== 0) {
      if (box) box.innerHTML = '<div class="text-danger small">' + esc(res.msg) + '</div>';
      return;
    }
    const d = res.data || {};
    pgState.models     = d.models || [];
    pgState.presets    = d.presets || [];
    pgState.quota      = d.quota || null;
    pgState.hasChannel = !!d.has_channel;
    if (!pgState.model) {
      pgState.model = d.default_model || (pgState.models[0] ? pgState.models[0].model_key : '');
    }
    pgRenderParam();
    pgRenderMsgs();
    pgRenderModelTag();
  }

  function pgCurModel() {
    for (let i = 0; i < pgState.models.length; i++) {
      if (pgState.models[i].model_key === pgState.model) return pgState.models[i];
    }
    return null;
  }

  function pgRenderParam() {
    const box = $('#pgParam');
    if (!box) return;

    const opts = pgState.models.map(function (m) {
      const sel = m.model_key === pgState.model ? ' selected' : '';
      return '<option value="' + esc(m.model_key) + '"' + sel + '>'
        + esc(m.display_name || m.model_key)
        + (m.runnable ? '' : '（无可用渠道）') + '</option>';
    }).join('');

    const presetHtml = (pgState.presets || []).map(function (p, i) {
      return '<button class="pg-preset" onclick="KS.pgPreset(' + i + ')">' + esc(p.t) + '</button>';
    }).join('');

    box.innerHTML = '<div class="pg-lb">模型</div>'
      + '<select id="pgModel" class="form-select form-select-sm" onchange="KS.pgModelChange()">'
      + (opts || '<option value="">（暂无可用模型）</option>') + '</select>'
      + '<div class="pg-meta" id="pgRatio"></div>'
      + '<div class="pg-lb">系统提示词</div>'
      + '<textarea id="pgSystem" class="form-control form-control-sm" rows="4" placeholder="给模型设定角色与输出要求，留空则不加" '
      + 'oninput="KS.pgSetSystem(this.value)">' + esc(pgState.system) + '</textarea>'
      + '<div class="pg-lb">预设角色</div><div>' + (presetHtml || '<span class="text-muted small">—</span>') + '</div>'
      + '<div class="pg-lb">温度 <span class="text-muted fw-normal" id="pgTempVal"></span></div>'
      + '<input id="pgTemp" type="range" class="form-range" min="0" max="2" step="0.1" value="' + pgState.temperature + '" oninput="KS.pgTempChange()">'
      + '<div class="pg-lb">最大输出 token（0 = 不限制）</div>'
      + '<input id="pgMaxTok" type="number" class="form-control form-control-sm" min="0" max="8192" value="' + (pgState.max_tokens || 0) + '" onchange="KS.pgSetMax(this.value)">'
      + '<div class="pg-lb">我的额度</div><div class="pg-quota" id="pgQuota"></div>';

    pgTempChange();
    pgRenderRatio();
    pgRenderQuota();
  }

  function pgRenderRatio() {
    const el = $('#pgRatio');
    if (!el) return;
    const m = pgCurModel();
    if (!m) { el.textContent = pgState.models.length ? '' : '管理员还没有添加 AI 渠道，请先到「AI 中转 → 渠道」配置。'; return; }
    el.textContent = '计费倍率：输入 ×' + m.prompt_ratio + '，输出 ×' + m.completion_ratio
      + (m.runnable ? '' : '（该模型当前没有可用渠道，调用会失败）');
  }

  function pgRenderModelTag() {
    const el = $('#pgModelTag');
    if (!el) return;
    el.textContent = pgState.model ? pgState.model : '未选择模型';
  }

  function pgRenderQuota() {
    const box = $('#pgQuota');
    if (!box) return;
    const q = pgState.quota;
    if (!q) { box.innerHTML = '<span class="text-muted">暂无额度信息</span>'; return; }
    const row = function (label, used, limit, left) {
      return '<div class="row"><span>' + label + '</span><b>' + esc(String(used))
        + (limit > 0 ? ' / ' + esc(String(limit)) + '（余 ' + esc(String(left)) + '）' : ' / 不限') + '</b></div>';
    };
    box.innerHTML = '<div class="row"><span>总余额</span><b>' + esc(String(q.balance)) + ' 点</b></div>'
      + row('今日', q.daily_used, q.daily_limit, q.remaining_daily)
      + row('本周', q.weekly_used, q.weekly_limit, q.remaining_weekly)
      + row('本月', q.monthly_used, q.monthly_limit, q.remaining_monthly)
      + '<div class="row"><span>累计消耗</span><b>' + esc(String(q.total_used)) + ' 点</b></div>';
  }

  function pgRenderMsgs() {
    const host = $('#pgMsgs');
    if (!host) return;
    if (!pgState.msgs.length) {
      host.innerHTML = '<div class="pg-empty"><i class="bi bi-joystick" style="font-size:34px;display:block;margin-bottom:8px"></i>'
        + 'AI 操练场：自由选模型、调温度、看每次调用扣多少点。<br>'
        + '左侧切换模型与预设角色，回答下方会显示 token 用量与扣点数。</div>';
      return;
    }
    host.innerHTML = pgState.msgs.map(function (m) {
      const who = m.role === 'user';
      const err = m.role === 'error';
      let stat = '';
      if (m.stat && !who && !err) {
        stat = '<div class="stat"><span>' + esc(m.stat.model) + '</span>'
          + '<span>输入 ' + m.stat.prompt + ' / 输出 ' + m.stat.completion + ' token</span>'
          + '<span>扣 ' + m.stat.points + ' 点</span>'
          + '<span>' + m.stat.latency + ' ms</span></div>';
      }
      return '<div class="msg ' + (who ? 'user' : 'ai') + '">'
        + '<div class="bubble' + (err ? ' text-danger' : '') + '">' + esc(m.content) + '</div>'
        + stat + '<div class="meta">' + (who ? '我' : (err ? '错误' : 'AI')) + ' · ' + esc(m.time) + '</div></div>';
    }).join('');
    host.scrollTop = host.scrollHeight;
  }

  App.pgModelChange = function () {
    const sel = $('#pgModel');
    if (!sel) return;
    pgState.model = sel.value;
    pgRenderRatio();
    pgRenderModelTag();
  };
  App.pgTempChange = function () {
    const r = $('#pgTemp'), lab = $('#pgTempVal');
    if (!r) return;
    pgState.temperature = parseFloat(r.value);
    if (lab) lab.textContent = pgState.temperature.toFixed(1);
  };
  App.pgSetSystem = function (v) { pgState.system = String(v || ''); };
  App.pgSetMax = function (v) {
    let n = parseInt(v, 10);
    if (!n || n < 0) n = 0;
    if (n > 8192) n = 8192;
    pgState.max_tokens = n;
    const el = $('#pgMaxTok');
    if (el) el.value = n;
  };
  App.pgPreset = function (i) {
    const p = pgState.presets[i];
    if (!p) return;
    pgState.system = p.s || '';
    const ta = $('#pgSystem');
    if (ta) ta.value = pgState.system;
    toast('已套用预设：' + p.t);
  };
  App.pgClear = function () {
    if (!pgState.msgs.length) return;
    if (!confirm('清空当前对话？（不会退还已消耗的点数）')) return;
    pgState.msgs = [];
    pgRenderMsgs();
  };
  App.pgCopy = function () {
    if (!pgState.msgs.length) { toast('对话还是空的', 'warn'); return; }
    const txt = pgState.msgs.map(function (m) {
      return (m.role === 'user' ? '我' : (m.role === 'error' ? '错误' : 'AI')) + '：' + m.content;
    }).join('\n\n');
    if (App.copyText) App.copyText(txt);
    else toast('当前浏览器不支持一键复制', 'warn');
  };

  App.pgSend = async function () {
    if (pgState.busy) return;
    const inp = $('#pgInput');
    if (!inp) return;
    const text = inp.value.trim();
    if (!text) return;
    if (!pgState.model) { toast('请先选择一个模型', 'warn'); return; }

    inp.value = '';
    pgState.msgs.push({ role: 'user', content: text, time: nowHM() });

    // 先占位一个 assistant 消息，后续流式往里追加
    const assistantMsg = { role: 'assistant', content: '', time: nowHM(), stat: null, _live: true };
    pgState.msgs.push(assistantMsg);
    const assistantIdx = pgState.msgs.length - 1;
    pgRenderMsgs();

    const btn = $('#pgSendBtn');
    pgState.busy = true;
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>'; }

    // system 单独字段回传；history 只含 user/assistant
    const history = pgState.msgs.filter(function (m) {
      return m.role === 'user' || m.role === 'assistant';
    }).filter(function (m) { return m.content || m === assistantMsg; })
      .map(function (m) { return { role: m.role, content: m.content }; });

    const payload = {
      model: pgState.model,
      system: pgState.system || '',
      messages: history,
      temperature: pgState.temperature,
      max_tokens: pgState.max_tokens || 0,
    };

    let ok = false;
    let finalErr = '';
    try {
      ok = await pgSendStream(payload, assistantIdx, function (stat) {
        assistantMsg.stat = stat;
        // 渲染一次底部统计
        pgRenderMsgs();
      }, function (errMsg) {
        finalErr = errMsg;
      });
    } catch (e) {
      finalErr = '网络异常：' + (e && e.message ? e.message : e);
    }

    pgState.busy = false;
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-send-fill"></i>'; }
    if (gone('pgMsgs')) return;

    if (!ok) {
      // 流式失败：尝试 fallback 到非流式接口
      let fb = null;
      try {
        fb = await POST('/api/playground/chat', payload);
      } catch (e) { fb = { code: 1, msg: '网络异常，请重试' }; }
      if (gone('pgMsgs')) return;
      if (fb && fb.code === 0) {
        const d = fb.data || {};
        assistantMsg.content = d.content || '';
        assistantMsg.stat = {
          model: d.model || pgState.model,
          prompt: (d.usage && d.usage.prompt_tokens) || 0,
          completion: (d.usage && d.usage.completion_tokens) || 0,
          points: d.points || 0,
          latency: d.latency_ms || 0,
        };
        if (d.quota) { pgState.quota = d.quota; pgRenderQuota(); }
        pgRenderMsgs();
        toast('（流式不可用，已回退到一次性返回）', 'warn');
      } else {
        assistantMsg.role = 'error';
        assistantMsg.content = (fb && fb.msg) || finalErr || '调用失败';
        assistantMsg._live = false;
        pgRenderMsgs();
        toast(assistantMsg.content, 'err');
      }
      return;
    }
    // 拉一次最新额度
    try { await pgLoad(); } catch (e) {}
  };

  /**
   * 用 fetch + ReadableStream 读 SSE。
   * 返回 true 表示服务端 OK，false 表示失败（可能为流式不可用）。
   */
  async function pgSendStream(payload, assistantIdx, onFinalStat, onError) {
    const host = $('#pgMsgs');
    const bubble = host ? host.querySelector('.msg.ai:last-child .bubble') : null;

    let resp;
    try {
      resp = await fetch('/api/playground/stream', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'text/event-stream' },
        body: JSON.stringify(payload),
      });
    } catch (e) {
      onError('网络异常：' + (e && e.message ? e.message : e));
      return false;
    }
    if (!resp.ok) {
      // 流式接口本身出错（路由不存在 / 服务器拒绝）→ 回退到非流式
      onError('流式接口 HTTP ' + resp.status);
      return false;
    }
    if (!resp.body || typeof resp.body.getReader !== 'function') {
      onError('当前浏览器不支持 ReadableStream');
      return false;
    }

    const reader = resp.body.getReader();
    const decoder = new TextDecoder('utf-8');
    let buf = '';
    let acc = '';
    let lastStat = null;
    let isFirstChunk = true;
    let lastErrMsg = '';

    function append(delta) {
      acc += delta;
      const m = pgState.msgs[assistantIdx];
      if (m) { m.content = acc; m._live = true; }
      if (bubble) {
        bubble.textContent = acc;
        if (host) host.scrollTop = host.scrollHeight;
      }
    }

    while (true) {
      let chunk;
      try {
        const r = await reader.read();
        chunk = r.value;
        if (r.done) break;
      } catch (e) {
        lastErrMsg = '读取流失败：' + (e && e.message ? e.message : e);
        break;
      }
      buf += decoder.decode(chunk, { stream: true });
      // 按 \n\n 拆事件
      let idx;
      while ((idx = buf.indexOf('\n\n')) >= 0) {
        const ev = buf.slice(0, idx);
        buf = buf.slice(idx + 2);
        if (ev.charAt(0) === ':') continue; // SSE 注释（用于换渠道提示）
        const dataLine = ev.split('\n').filter(function (l) { return l.indexOf('data:') === 0; }).map(function (l) { return l.slice(5).trimStart(); }).join('\n');
        if (!dataLine) continue;
        if (dataLine === '[DONE]') { buf = ''; break; }
        let parsed = null;
        try { parsed = JSON.parse(dataLine); } catch (e) { continue; }
        if (parsed && parsed.error) {
          lastErrMsg = (parsed.error && parsed.error.message) || '上游错误';
          continue;
        }
        const choices = (parsed && parsed.choices) || [];
        const choice = choices[0] || {};
        const delta = (choice.delta && typeof choice.delta.content === 'string') ? choice.delta.content : '';
        if (delta) {
          if (isFirstChunk) { isFirstChunk = false; }
          append(delta);
        }
        if (parsed.usage) {
          lastStat = {
            model: parsed.model || pgState.model,
            prompt: parsed.usage.prompt_tokens || 0,
            completion: parsed.usage.completion_tokens || 0,
            points: parsed.usage.points || 0,
            latency: 0,
            channel: parsed.usage.channel_id || 0,
          };
        }
      }
    }
    if (lastStat && onFinalStat) onFinalStat(lastStat);
    if (lastErrMsg) onError(lastErrMsg);
    if (isFirstChunk) {
      // 一片内容都没收到 → 当作流式失败
      onError('未收到流式数据');
      return false;
    }
    return !lastErrMsg;
  }

  // -------- 通知公告 --------
  let ntState = { list: [], drafts: [], unread: 0, isAdmin: false };

  ROUTERS['notice'] = function (host) {
    host.innerHTML = '<div class="card mb-3"><div class="card-h"><i class="bi bi-megaphone text-primary"></i>'
      + '<span class="tt">通知公告</span><div class="flex-grow-1"></div>'
      + '<span class="small text-muted me-2" id="ntUnreadTip"></span>'
      + '<button class="btn btn-sm btn-outline-secondary me-1" onclick="KS.noticeReadAll()">全部已读</button>'
      + '<button class="btn btn-sm btn-outline-primary me-1" onclick="KS.noticeReload()"><i class="bi bi-arrow-clockwise"></i></button>'
      + '<button class="btn btn-sm btn-primary d-none" id="ntNewBtn" onclick="KS.noticeForm()"><i class="bi bi-plus-lg"></i> 发布公告</button>'
      + '</div><div class="card-b" id="ntList"><div class="text-center text-muted small py-4">加载中…</div></div></div>'
      + '<div class="card d-none" id="ntDraftCard"><div class="card-h"><i class="bi bi-file-earmark text-secondary"></i>'
      + '<span class="tt">草稿箱</span></div><div class="card-b p-0">'
      + '<div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr>'
      + '<th>标题</th><th>范围</th><th>时间</th><th style="width:170px">操作</th></tr></thead>'
      + '<tbody id="ntDraftBody"></tbody></table></div></div></div>';
    noticeLoad();
  };

  async function noticeLoad() {
    const res = await GET('/api/notice/mine');
    if (gone('ntList')) return;
    const box = $('#ntList');
    if (res.code !== 0) { if (box) box.innerHTML = '<div class="text-danger small">' + esc(res.msg) + '</div>'; return; }
    const d = res.data || {};
    ntState.list    = d.list || [];
    ntState.drafts  = d.drafts || [];
    ntState.unread  = d.unread || 0;
    ntState.isAdmin = !!d.is_admin;
    if (App.boot) App.boot.notice_unread = ntState.unread;
    buildNav();
    noticeRender();
  }

  function noticeScopeText(s) {
    return s === 'teacher' ? '仅教师' : (s === 'admin' ? '仅管理员' : '全体');
  }

  function noticeRender() {
    const box = $('#ntList');
    if (!box) return;

    const newBtn = $('#ntNewBtn');
    if (newBtn) newBtn.classList.toggle('d-none', !ntState.isAdmin);
    const tip = $('#ntUnreadTip');
    if (tip) tip.textContent = ntState.unread > 0 ? ('未读 ' + ntState.unread + ' 条') : '已全部阅读';

    if (!ntState.list.length) {
      box.innerHTML = '<div class="text-center text-muted small py-4">暂时没有通知公告。</div>';
    } else {
      box.innerHTML = ntState.list.map(function (n) {
        const typeBadge = n.type === 'announce'
          ? '<span class="badge bg-primary me-1">公告</span>'
          : '<span class="badge bg-info text-dark me-1">通知</span>';
        const pin = n.pinned ? '<span class="badge bg-danger me-1">置顶</span>' : '';
        const dot = (!n.read && !ntState.isAdmin) ? '<span class="badge rounded-pill bg-danger ms-1">未读</span>' : '';
        const ops = ntState.isAdmin
          ? '<div class="btn-group btn-group-sm">'
            + '<button class="btn btn-outline-secondary" onclick="KS.noticePin(' + n.id + ',' + (n.pinned ? 0 : 1) + ')">' + (n.pinned ? '取消置顶' : '置顶') + '</button>'
            + '<button class="btn btn-outline-secondary" onclick="KS.noticeToggle(' + n.id + ',0)">下架</button>'
            + '<button class="btn btn-outline-secondary" onclick="KS.noticeForm(' + n.id + ')">编辑</button>'
            + '<button class="btn btn-outline-secondary" onclick="KS.noticeStat(' + n.id + ')">已读</button>'
            + '<button class="btn btn-outline-danger" onclick="KS.noticeDel(' + n.id + ')">删</button></div>'
          : '';
        return '<div class="nt-item' + (n.read ? '' : ' unread') + '" onclick="KS.noticeDetail(' + n.id + ')">'
          + '<div class="nt-t">' + typeBadge + pin + esc(n.title) + dot + '</div>'
          + '<div class="nt-s">' + esc(n.summary || '') + '</div>'
          + '<div class="nt-m">' + esc(n.publisher || '系统') + ' · ' + esc(fmtTs(n.created_at))
          + ' · ' + noticeScopeText(n.scope) + '</div>'
          + (ops ? '<div class="nt-ops" onclick="event.stopPropagation()">' + ops + '</div>' : '')
          + '</div>';
      }).join('');
    }

    const card = $('#ntDraftCard'), body = $('#ntDraftBody');
    if (card) card.classList.toggle('d-none', !(ntState.isAdmin && ntState.drafts.length));
    if (body) {
      body.innerHTML = (ntState.drafts || []).map(function (n) {
        return '<tr><td>' + esc(n.title) + '</td><td class="small">' + noticeScopeText(n.scope) + '</td>'
          + '<td class="small text-muted">' + esc(fmtTs(n.created_at)) + '</td>'
          + '<td style="white-space:nowrap"><button class="btn btn-sm btn-outline-success" onclick="KS.noticeToggle(' + n.id + ',1)">发布</button> '
          + '<button class="btn btn-sm btn-outline-secondary" onclick="KS.noticeForm(' + n.id + ')">编辑</button> '
          + '<button class="btn btn-sm btn-outline-danger" onclick="KS.noticeDel(' + n.id + ')">删</button></td></tr>';
      }).join('');
    }
  }

  App.noticeReload = function () { noticeLoad(); };

  App.noticeDetail = async function (id) {
    const res = await GET('/api/notice/detail', { id: id });
    if (res.code !== 0) { toast(res.msg, 'err'); return; }
    const n = res.data.notice;
    openModal(esc(n.title), ''
      + '<div class="small text-muted mb-2">' + esc(n.publisher || '系统') + ' · ' + esc(fmtTs(n.created_at))
      + ' · ' + noticeScopeText(n.scope) + (n.pinned ? ' · 置顶' : '') + '</div>'
      + '<div style="white-space:pre-wrap;line-height:1.75">' + esc(n.content) + '</div>',
      [{ t: '关闭', c: 'btn-secondary', act: function () { hideModal(); noticeLoad(); } }]);
  };

  App.noticeReadAll = async function () {
    const res = await POST('/api/notice/readAll', {});
    if (res.code !== 0) { toast(res.msg, 'err'); return; }
    toast('已全部标记为已读');
    noticeLoad();
  };

  App.noticePin = async function (id, v) {
    const res = await POST('/api/notice/pin', { id: id, pinned: v });
    if (res.code !== 0) { toast(res.msg, 'err'); return; }
    toast(res.msg); noticeLoad();
  };
  App.noticeToggle = async function (id, v) {
    const res = await POST('/api/notice/toggle', { id: id, status: v });
    if (res.code !== 0) { toast(res.msg, 'err'); return; }
    toast(res.msg); noticeLoad();
  };
  App.noticeDel = async function (id) {
    if (!confirm('删除这条公告？已读回执也会一并清除。')) return;
    const res = await POST('/api/notice/delete', { id: id });
    if (res.code !== 0) { toast(res.msg, 'err'); return; }
    toast('已删除'); noticeLoad();
  };
  App.noticeStat = async function (id) {
    const res = await GET('/api/notice/readStat', { id: id });
    if (res.code !== 0) { toast(res.msg, 'err'); return; }
    const d = res.data;
    const rows = (d.readers || []).map(function (r) {
      return '<tr><td>' + esc(r.name) + '</td><td class="small text-muted">' + esc(fmtTs(r.read_at)) + '</td></tr>';
    }).join('');
    openModal('已读情况', '<div class="mb-2">已读 <b>' + d.read + '</b> / ' + d.total + ' 人</div>'
      + (rows ? '<div style="max-height:320px;overflow:auto"><table class="table table-sm"><tbody>' + rows + '</tbody></table></div>'
              : '<div class="text-muted small">还没有人读过。</div>'),
      [{ t: '关闭', c: 'btn-secondary', act: hideModal }]);
  };

  App.noticeForm = function (id) {
    const all = (ntState.list || []).concat(ntState.drafts || []);
    const n = id ? all.filter(function (x) { return x.id === id; })[0] : null;
    openModal(id ? '编辑公告' : '发布公告', ''
      + '<div class="mb-2"><label class="form-label">标题</label>'
      + '<input id="ntTitle" class="form-control" maxlength="200" value="' + esc(n ? n.title : '') + '" placeholder="如：关于本学期课时录入截止的通知"></div>'
      + '<div class="row g-2 mb-2">'
      + '<div class="col-6"><label class="form-label">类型</label>'
      + '<select id="ntType" class="form-select">'
      + '<option value="notice"' + (n && n.type === 'announce' ? '' : ' selected') + '>通知</option>'
      + '<option value="announce"' + (n && n.type === 'announce' ? ' selected' : '') + '>公告</option></select></div>'
      + '<div class="col-6"><label class="form-label">可见范围</label>'
      + '<select id="ntScope" class="form-select">'
      + '<option value="all"' + (!n || n.scope === 'all' ? ' selected' : '') + '>全体</option>'
      + '<option value="teacher"' + (n && n.scope === 'teacher' ? ' selected' : '') + '>仅教师</option>'
      + '<option value="admin"' + (n && n.scope === 'admin' ? ' selected' : '') + '>仅管理员</option></select></div></div>'
      + '<div class="mb-2"><label class="form-label">正文</label>'
      + '<textarea id="ntContent" class="form-control" rows="8" placeholder="支持纯文本，段落之间空一行即可">' + esc(n ? (n.content || '') : '') + '</textarea></div>'
      + '<div class="form-check"><input class="form-check-input" type="checkbox" id="ntPin"' + (n && n.pinned ? ' checked' : '') + '>'
      + '<label class="form-check-label small" for="ntPin">置顶显示</label></div>',
      [{ t: '保存并发布', c: 'btn-primary', act: function () { noticeSave(id, true); } }]
        .concat(id ? [] : [{ t: '存为草稿', c: 'btn-outline-secondary', act: function () { noticeSave(0, false); } }]));
  };

  async function noticeSave(id, publish) {
    const title = ($('#ntTitle').value || '').trim();
    const content = ($('#ntContent').value || '').trim();
    if (!title) { toast('请填写标题', 'warn'); return; }
    if (!content) { toast('请填写正文', 'warn'); return; }
    const res = await POST('/api/notice/save', {
      id: id || 0,
      title: title,
      content: content,
      type: $('#ntType').value,
      scope: $('#ntScope').value,
      pinned: $('#ntPin').checked ? 1 : 0,
      publish: publish ? 1 : 0,
    });
    if (res.code !== 0) { toast(res.msg, 'err'); return; }
    hideModal();
    toast(res.msg);
    noticeLoad();
  }

  ROUTERS['ai'] = function (host) {
    host.innerHTML = `<div class="ai-wrap">
      <div class="ai-side"><div class="hd"><span><i class="bi bi-chat-dots me-1"></i>会话</span>
        <button class="btn btn-sm btn-primary py-0" onclick="KS.aiNew()"><i class="bi bi-plus-lg"></i></button></div>
        <div class="list" id="aiConvList"><div class="text-center text-muted small py-4">加载中…</div></div></div>
      <div class="ai-main">
        <div class="ai-actions" id="aiQuickActs">
          <button class="ai-act" onclick="KS.aiQuick('evaluate','')"><i class="bi bi-speedometer2 me-1"></i>工作量评估</button>
          <button class="ai-act" onclick="KS.aiQuick('proof','')"><i class="bi bi-file-earmark-text me-1"></i>课时证明</button>
          <button class="ai-act" onclick="KS.aiQuick('qa','本学期课酬多少')">课酬查询</button>
          <button class="ai-act" onclick="KS.aiQuick('qa','课时峰值周')">峰值周</button>
          <button class="ai-act" onclick="KS.aiQuick('qa','2026-10 多少课时')">10月查询</button>
        </div>
        <div class="ai-msgs" id="aiMsgs"></div>
        <div class="ai-in">
          <textarea id="aiInput" class="form-control" placeholder="粘贴课表文字批量建课；或问我课时问题…" onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();KS.aiSend();}"></textarea>
          <button class="btn btn-primary px-3" onclick="KS.aiSend()"><i class="bi bi-send-fill"></i></button>
        </div>
      </div>
    </div>`;
    aiInit();
  };
  let aiState = { convs: [], cur: 0, lastParseRows: [] };
  async function aiInit(){
    const res = await GET('/api/ai/bootstrap');
    if (res.code!==0) return;
    aiState.convs = res.data.conversations||[];
    aiState.cur = res.data.current_id;
    renderAiConvs();
    renderAiMsgs(res.data.messages||[]);
  }
  function renderAiConvs(){
    const host=$('#aiConvList'); if(!host)return;
    if(!aiState.convs.length){ host.innerHTML='<div class="text-center text-muted small py-4">还没有会话</div>'; return; }
    host.innerHTML = aiState.convs.map(c => '<div class="ai-conv' + (c.id===aiState.cur?' on':'') + '" onclick="KS.aiSwitch('+c.id+')"><i class="bi bi-chat"></i><span class="tit">' + esc(c.title) + '</span>'
      + (c.id===aiState.cur?'<i class="bi bi-x-lg text-danger" style="font-size:11px" onclick="event.stopPropagation();KS.aiDel('+c.id+')"></i>':'') + '</div>').join('');
  }
  function renderAiMsgs(msgs){
    const host=$('#aiMsgs'); if(!host)return;
    if(!msgs.length){ host.innerHTML='<div class="cal-empty"><i class="bi bi-robot" style="font-size:34px;display:block;margin-bottom:8px"></i>你好，我是课时智能助手。可以：<br>① 粘贴课表文字 → 批量建课<br>② 生成学期工作量评估<br>③ 查询某月/课程课时与课酬<br>④ 生成课时证明文案</div>'; return; }
    host.innerHTML = msgs.map(m => msgHtml(m)).join('');
    host.scrollTop = host.scrollHeight;
  }
  function msgHtml(m){
    const who = m.role==='user';
    let extra='';
    // 解析预览确认按钮
    if (!who && m.intent==='parse' && m.payload && m.payload.rows && m.payload.rows.length) {
      const rows=m.payload.rows; const ok=rows.filter(r=>!r.conflict).length;
      extra = '<div class="mt-1"><div class="small text-muted">已识别 '+rows.length+' 条，可导入 '+ok+' 条</div>'
        + '<button class="btn btn-sm btn-primary mt-1" onclick="KS.aiConfirmParse('+m.id+')"><i class="bi bi-check2-all me-1"></i>确认导入 '+ok+' 条</button></div>';
    }
    return '<div class="msg '+who+'"><div class="bubble">'+esc(m.content)+'</div>'+extra+'<div class="meta">'+(who?'我':'AI')+' · '+m.time+'</div></div>';
  }
  App.aiNew = async function(){ const res=await POST('/api/ai/create',{}); if(res.code===0){ aiState.convs.unshift({id:res.data.id,title:'新的对话'}); aiState.cur=res.data.id; renderAiConvs(); renderAiMsgs([]);} };
  App.aiSwitch = async function(id){ aiState.cur=id; renderAiConvs(); const res=await GET('/api/ai/messages',{conversation_id:id}); if(aiState.cur!==id) return; // 已切到别的会话，丢弃过期返回
    if(res.code===0) renderAiMsgs(res.data.messages||[]); };
  App.aiDel = async function(id){ if(!confirm('删除该会话？'))return; const res=await POST('/api/ai/delete',{conversation_id:id}); if(res.code===0){ aiState.convs=aiState.convs.filter(c=>c.id!==id); if(aiState.cur===id){aiState.cur= aiState.convs[0]?aiState.convs[0].id:0;} if(aiState.cur) await App.aiSwitch(aiState.cur); else { renderAiConvs(); renderAiMsgs([]); aiState.lastParseRows=[]; } } };
  App.aiSend = async function(){
    const inp=$('#aiInput'); const text=inp.value.trim(); if(!text)return;
    inp.value='';
    const cur = $('#aiMsgs');
    cur.insertAdjacentHTML('beforeend', msgHtml({role:'user',content:text,time:nowHM()}));
    cur.scrollTop = cur.scrollHeight;
    // 本地先给个忙碌气泡
    const tmp = document.createElement('div'); tmp.className='msg ai'; tmp.innerHTML='<div class="bubble text-muted"><i class="bi bi-three-dots"></i></div>'; cur.appendChild(tmp); cur.scrollTop=cur.scrollHeight;
    const payload = { text, intent: guessIntentClient(text), conversation_id: aiState.cur || undefined };
    try{
      const res = await POST('/api/ai/chat', payload);
      tmp.remove();
      if (res.code!==0){ cur.insertAdjacentHTML('beforeend', msgHtml({role:'assistant',content:res.msg||'出错了',time:nowHM()})); return; }
      const d=res.data;
      // 解析预览结果暂存，供「确认导入」使用
      if (d.intent==='parse' && Array.isArray(d.rows)) aiState.lastParseRows = d.rows;
      cur.insertAdjacentHTML('beforeend', msgHtml({role:'assistant',content:d.reply||'',intent:d.intent,payload:d,time:nowHM()}));
      // 刷新会话列表标题
      if(aiState.cur!==d.conversation_id){ aiState.cur=d.conversation_id; }
      aiReloadList();
    }catch(e){ tmp.remove(); }
  };
  function nowHM(){ const d=new Date(); return ('0'+d.getHours()).slice(-2)+':'+('0'+d.getMinutes()).slice(-2); }
  async function aiReloadList(){ const r=await GET('/api/ai/conversations'); if(r.code===0){ aiState.convs=r.data; renderAiConvs(); } }
  function guessIntentClient(t){
    if(/\d+\s*[-~－—至]\s*\d+\s*周/.test(t)&&/\d+\s*[-~－—]\s*\d+\s*节/.test(t)) return 'parse';
    if(/周[一二三四五六日天]/.test(t)&&/\d+\s*[-~－—]\s*\d+\s*节/.test(t)) return 'parse';
    if(/(工作量|饱和度|负荷|评估|分析|说明文案)/.test(t)) return 'evaluate';
    if(/(证明|证明信|证明材|开具)/.test(t)) return 'proof';
    if(/(多少|几节|查询|统计|峰值|哪些|什么时候|课酬|课时费)/.test(t)) return 'qa';
    return '';
  }
  App.aiQuick = function(intent, text){
    $('#aiInput').value = text||aiIntentHint(intent);
    // evaluate/proof 直接发空文本由后端用默认；但我们提供提示文案引导用户确认
    if (intent==='evaluate') $('#aiInput').value='请帮我生成本学期工作量评估报告与教务处说明文案';
    if (intent==='proof') $('#aiInput').value='请帮我生成课时证明';
    if (intent==='evaluate'||intent==='proof') { App.aiSend(); }
  };
  function aiIntentHint(i){ return ''; }
  App.aiConfirmParse = async function(messageId){
    // 把最近一次解析预览的 rows 回传给后端，由后端带归属校验写库
    const rows = aiState.lastParseRows || [];
    if (!rows.length) { toast('当前没有待确认的解析结果，请先粘贴课表', 'warn'); return; }
    const res = await POST('/api/ai/chat', { text:'确认导入', intent:'parse_confirm', conversation_id: aiState.cur, payload:{ rows } });
    const cur=$('#aiMsgs');
    if(res.code===0){
      // 记忆：把这次确认导入的"课程→班级"也学一遍，下次再录入同课程自动填班级
      try {
        const m = readClassPref();
        for (const r of rows) {
          if (r && r.course_name && r.classes) {
            // 优先用解析预览里的 course_id（若存在），否则按课程名兜底
            if (r.course_id) m[String(r.course_id)] = r.classes;
            else m['__by_name__' + r.course_name] = r.classes;
          }
        }
        writeClassPref(m);
      } catch (e) {}
      cur.insertAdjacentHTML('beforeend', msgHtml({role:'assistant',content:res.data.reply||'',intent:'parse_confirm',time:nowHM()})); cur.scrollTop=cur.scrollHeight; aiReloadList(); calCache={}; reloadBootCourses(); if(App.page==='my-lessons') loadLessons(); if(App.page!=='my-lessons') refreshTodoIfDashboard();
    }
    else toast(res.msg,'err');
  };

  // =====================================================================
  // 15. 管理员页面
  // =====================================================================

  // -------- 用户管理 --------
  let adminUserCache = []; // 最近一次教师列表（供编辑弹窗回显）
  ROUTERS['admin-users'] = function(host){
    host.innerHTML='<div class="card"><div class="card-h"><i class="bi bi-people text-primary"></i><span class="tt">教师账号管理</span>'
      +'<div class="flex-grow-1"></div>'
      +'<button class="btn btn-primary btn-sm" onclick="KS.adminTeacherForm()"><i class="bi bi-person-plus me-1"></i>新增账号</button></div>'
      +'<div class="table-responsive"><table class="table"><thead><tr><th>账号</th><th>姓名</th><th>院系</th><th>岗位</th><th>角色</th><th>状态</th><th class="text-end">课时</th><th class="text-end">课酬</th><th>最近登录</th><th style="width:210px">操作</th></tr></thead><tbody id="adminUserTbody"></tbody></table></div></div>';
    loadAdminUsers();
  };
  async function loadAdminUsers(){
    const tbody=$('#adminUserTbody'); if(!tbody)return;
    tbody.innerHTML='<tr><td colspan="10" class="text-center py-4 text-muted">加载中…</td></tr>';
    const term=curTerm();
    const res=await GET('/api/admin/teachers', term?{term_id:term.id}:null);
    if(res.code!==0){tbody.innerHTML='<tr><td colspan="10" class="text-center py-4">'+esc(res.msg)+'</td></tr>';return;}
    if (gone('adminUserTbody')) return;
    const list=res.data||[];
    adminUserCache = list;
    if(!list.length){tbody.innerHTML='<tr><td colspan="10"><div class="dk-empty"><i class="bi bi-people"></i>暂无账号</div></td></tr>';return;}
    tbody.innerHTML=list.map(u=>{
      const isAdmin=u.role==='admin';
      return '<tr>'
        +'<td><code>'+esc(u.username)+'</code>'+(u.is_self?' <span class="badge bg-info">我</span>':'')+'</td>'
        +'<td><b>'+esc(u.name)+'</b></td><td>'+esc(u.department||'—')+'</td><td>'+esc(u.position||'—')+'</td>'
        +'<td>'+(isAdmin?'<span class="badge bg-danger-subtle text-danger">管理员</span>':'<span class="badge bg-secondary-subtle text-secondary">教师</span>')+'</td>'
        +'<td>'+(u.status?'<span class="badge bg-success">启用</span>':'<span class="badge bg-warning text-dark">待启用</span>')+'</td>'
        +'<td class="text-end">'+u.cnt+'</td><td class="text-end" style="color:#059669;font-weight:600">¥'+fmtMoney(u.amount)+'</td>'
        +'<td class="small text-muted">'+(u.last_login_at?ts(u.last_login_at):'—')+'</td>'
        +'<td><button class="btn btn-sm btn-outline-secondary py-0" onclick="KS.adminTeacherForm('+u.id+')">编辑</button> '
        +'<button class="btn btn-sm btn-outline-warning py-0" onclick="KS.adminResetPwd('+u.id+','+jsStr(u.name)+')">改密</button> '
        +(u.status?'<button class="btn btn-sm btn-outline-danger py-0" onclick="KS.adminToggle('+u.id+','+jsStr(u.name)+')">禁用</button>':'<button class="btn btn-sm btn-outline-success py-0" onclick="KS.adminToggle('+u.id+','+jsStr(u.name)+')">启用</button>')
        +(u.is_self?'':' <button class="btn btn-sm btn-outline-danger py-0" onclick="KS.adminDelUser('+u.id+','+jsStr(u.name)+')">删</button>')
        +'</td></tr>';
    }).join('');
  }
  function ts(s){ if(!s)return''; const d=new Date(s*1000); return d.getFullYear()+'-'+('0'+(d.getMonth()+1)).slice(-2)+'-'+('0'+d.getDate()).slice(-2); }
  App.refreshAdminUsers=function(){ loadAdminUsers(); };
  App.adminTeacherForm=async function(id){
    // 编辑时从最近一次列表缓存回显（列表接口已含 username/name/department_id/position/role/is_self）
    const u= id ? (adminUserCache.find(x=>x.id==id)||null) : null;
    const depts=await loadDepts();
    const deptOpts=depts.map(d=>'<option value="'+d.id+'">'+esc(d.name)+'</option>').join('');
    const isEdit=!!id;
    const lockSelf=isEdit && u && u.is_self && u.role==='admin'; // 不允许把自己降级/改名锁账号
    let roleOpts;
    if (lockSelf) {
      roleOpts='<option value="admin" selected>管理员</option>';
    } else {
      roleOpts='<option value="teacher"'+(u&&u.role==='teacher'?' selected':'')+'>教师</option>'
        +'<option value="admin"'+(u&&u.role==='admin'?' selected':'')+'>管理员</option>';
    }
    openModal(isEdit?'编辑账号':'新增教师账号', `
      <input type="hidden" id="atId" value="${id||0}">
      <div class="mb-2"><label class="form-label">登录账号</label><input id="atUser" class="form-control" value="${u?esc(u.username):''}" placeholder="字母数字下划线，3-32位" ${isEdit?'disabled':''}></div>
      <div class="mb-2"><label class="form-label">姓名</label><input id="atName" class="form-control" value="${u?esc(u.name):''}"></div>
      ${!isEdit?'<div class="mb-2"><label class="form-label">初始密码</label><input id="atPwd" class="form-control" value="" placeholder="至少6位"></div>':''}
      <div class="row g-2">
        <div class="col-6"><label class="form-label">所属院系</label><select id="atDept" class="form-select">${deptOpts}</select></div>
        <div class="col-6"><label class="form-label">岗位</label><input id="atPos" class="form-control" value="${u?esc(u.position||''):''}"></div>
      </div>
      <div class="mb-2"><label class="form-label">角色</label>
        <select id="atRole" class="form-select">${roleOpts}</select></div>`,
      [ {t:'取消',c:'btn-light',x:true}, {t:isEdit?'保存':'创建账号',c:'btn-primary',act:()=>KS.adminTeacherSave()} ]);
    if(depts.length) $('#atDept').value = u&&u.department_id?u.department_id:depts[0].id;
  };
  async function loadDepts(){ const res=await GET('/api/admin/departments'); return res.code===0?(res.data||[]):[]; }
  App.adminTeacherSave=async function(){
    const id=parseInt($('#atId').value||0,10);
    const payload={ id:id||undefined, username:$('#atUser').value.trim(), name:$('#atName').value.trim(),
      department_id: parseInt($('#atDept').value||0,10), position:$('#atPos').value.trim(), role:$('#atRole').value };
    if(!id) payload.password=$('#atPwd').value;
    const res=await POST('/api/admin/teacherSave',payload);
    if(res.code===0){ toast(res.msg); hideModal(); loadAdminUsers(); reloadBootCourses(); }
    else toast(res.msg,'err');
  };
  App.adminResetPwd=async function(id,name){
    const p=prompt('为 '+name+' 设置新密码（至少6位）：'); if(!p)return;
    const res=await POST('/api/admin/teacherResetPwd',{id,password:p});
    res.code===0?toast('密码已重置为 '+p):toast(res.msg,'err');
  };
  App.adminToggle=async function(id,name){
    const res=await POST('/api/admin/teacherToggle',{id});
    res.code===0?toast(res.msg):toast(res.msg,'err');
    loadAdminUsers();
  };
  App.adminDelUser=async function(id,name){
    if(!confirm('删除账号 '+name+' ？'))return;
    const res=await POST('/api/admin/teacherDelete',{id});
    res.code===0?toast('已删除'):toast(res.msg,'err');
    loadAdminUsers();
  };

  // -------- AI 中转（渠道 / 模型倍率 / 额度分配）--------
  const AIHUB_TYPES = ['openai','claude','qwen','deepseek','gemini','custom'];
  let aihubState = { tab: 'channel', channels: [], models: [], quotas: [] };

  ROUTERS['admin-aihub']=function(host){
    host.innerHTML=`<div class="card"><div class="card-h"><i class="bi bi-robot text-primary"></i><span class="tt">AI 中转管理</span>
      <div class="flex-grow-1"></div>
      <div class="btn-group btn-group-sm" role="group">
        <button type="button" class="btn btn-outline-primary" id="aihubTabChannel" onclick="KS.aihubTab('channel')">渠道</button>
        <button type="button" class="btn btn-outline-primary" id="aihubTabModel" onclick="KS.aihubTab('model')">模型倍率</button>
        <button type="button" class="btn btn-outline-primary" id="aihubTabQuota" onclick="KS.aihubTab('quota')">额度分配</button>
      </div></div>
      <div class="card-b" id="aihubBody"><div class="text-center text-muted small py-4">加载中…</div></div></div>`;
    loadAihub();
  };

  async function loadAihub(){
    const [ch, qu] = await Promise.all([GET('/api/aichannel/index'), GET('/api/aiquota/all')]);
    if (ch.code===0){ aihubState.channels=(ch.data&&ch.data.channels)||[]; aihubState.models=(ch.data&&ch.data.models)||[]; }
    if (qu.code===0){ aihubState.quotas=(qu.data&&qu.data.list)||[]; }
    renderAihub();
  }

  App.aihubTab=function(t){ aihubState.tab=t; renderAihub(); };

  function renderAihub(){
    const body=$('#aihubBody'); if(!body) return;
    [['channel','Channel'],['model','Model'],['quota','Quota']].forEach(function(p){
      const b=$('#aihubTab'+p[1]);
      if(b) b.className='btn btn-sm '+(aihubState.tab===p[0]?'btn-primary':'btn-outline-primary');
    });
    if(aihubState.tab==='model') return renderAihubModel(body);
    if(aihubState.tab==='quota') return renderAihubQuota(body);
    return renderAihubChannel(body);
  }

  // ---------- 渠道 ----------
  function renderAihubChannel(body){
    const rows=aihubState.channels.map(function(c){
      const md=(c.models||[]);
      const mtxt = md.length ? esc(md.slice(0,3).join(', ')) + (md.length>3 ? ' 等'+md.length+'个' : '') : '<span class="text-muted">不限</span>';
      return `<tr>
        <td>${esc(c.name)}</td>
        <td><span class="badge bg-light text-dark">${esc(c.type)}</span></td>
        <td class="small text-muted" style="max-width:240px;word-break:break-all">${esc(c.base_url)}</td>
        <td>${c.key_configured?'<span class="text-success">已配置</span>':'<span class="text-danger">未配置</span>'}</td>
        <td class="small">${mtxt}</td>
        <td>${c.priority}</td>
        <td>${c.status?'<span class="badge bg-success">启用</span>':'<span class="badge bg-secondary">停用</span>'}</td>
        <td style="width:210px;white-space:nowrap">
          <button class="btn btn-sm btn-outline-primary" onclick="KS.aiChannelForm(${c.id})">编辑</button>
          <button class="btn btn-sm btn-outline-secondary" onclick="KS.aiChannelTest(${c.id})">测试</button>
          <button class="btn btn-sm btn-outline-danger" onclick="KS.aiChannelDel(${c.id})">删</button>
        </td></tr>`;
    }).join('');
    body.innerHTML=`<div class="d-flex justify-content-between align-items-center mb-2">
      <div class="small text-muted">共 ${aihubState.channels.length} 个渠道；按权重从高到低依次尝试，失败自动切换下一个。</div>
      <button class="btn btn-sm btn-primary" onclick="KS.aiChannelForm(0)"><i class="bi bi-plus-lg"></i> 新增渠道</button></div>
      <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr>
      <th>名称</th><th>类型</th><th>接口地址</th><th>Key</th><th>模型白名单</th><th>权重</th><th>状态</th><th>操作</th>
      </tr></thead><tbody>${rows||'<tr><td colspan="8" class="text-center text-muted py-4">暂无渠道。未配置渠道时，站内 AI 会回退到「基础配置 → 系统模型」。</td></tr>'}</tbody></table></div>`;
  }

  App.aiChannelForm=function(id){
    const c = id ? aihubState.channels.find(function(x){return x.id===id;}) : null;
    openModal(id?'编辑渠道':'新增渠道', `
      <input type="hidden" id="achId" value="${id||0}">
      <div class="mb-2"><label class="form-label">渠道名称</label><input id="achName" class="form-control" value="${c?esc(c.name):''}" placeholder="如 OpenAI-主用"></div>
      <div class="row g-2 mb-2">
        <div class="col-6"><label class="form-label">厂商类型</label><select id="achType" class="form-select">
          ${AIHUB_TYPES.map(function(t){return '<option value="'+t+'"'+(c&&c.type===t?' selected':'')+'>'+t+'</option>';}).join('')}
        </select></div>
        <div class="col-6"><label class="form-label">权重（越大越优先）</label><input id="achPriority" type="number" class="form-control" value="${c?c.priority:10}"></div>
      </div>
      <div class="mb-2"><label class="form-label">接口地址 Base URL</label><input id="achUrl" class="form-control" value="${c?esc(c.base_url):''}" placeholder="https://api.openai.com/v1"></div>
      <div class="mb-2"><label class="form-label">API Key</label><input id="achKey" type="password" class="form-control" autocomplete="new-password" placeholder="${c&&c.key_configured?'留空表示保留原 Key':'如 sk-xxxxxx'}"></div>
      <div class="mb-2">
        <div class="d-flex justify-content-between align-items-center">
          <label class="form-label mb-1">模型白名单（逗号分隔，留空=不限制）</label>
          <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" style="min-height:30px" onclick="KS.aiChannelFetchModels(this)">
            <i class="bi bi-arrow-down-circle me-1"></i>拉取模型
          </button>
        </div>
        <input id="achModels" class="form-control" value="${c?esc((c.models||[]).join(',')):''}" placeholder="gpt-4o-mini,gpt-4o">
        <div class="form-text" id="achModelsHint">填好上方「接口地址 + API Key + 厂商类型」后点「拉取模型」，自动回填；可再手动删减。</div>
      </div>
      <div class="form-check"><input class="form-check-input" type="checkbox" id="achStatus" ${(!c||c.status)?'checked':''}><label class="form-check-label">启用该渠道</label></div>
    `, [{t:'保存',c:'btn-primary',act:saveAiChannel}]);
  };

  async function saveAiChannel(){
    const models=($('#achModels').value||'').split(/[,，\s]+/).filter(Boolean);
    const res=await POST('/api/aichannel/save',{
      id: parseInt($('#achId').value,10)||0,
      name: $('#achName').value.trim(),
      type: $('#achType').value,
      base_url: $('#achUrl').value.trim(),
      api_key: $('#achKey').value.trim(),
      models: models,
      priority: parseInt($('#achPriority').value,10)||10,
      status: $('#achStatus').checked?1:0
    });
    if(res.code!==0){ toast(res.msg,'err'); return; }
    hideModal(); toast('已保存'); loadAihub();
  }

  // 弹窗内「拉取模型」：新增(未保存)时只用弹窗里填的 url/key/type 探测，回填白名单；
  // 已保存渠道且 key 留空时，后端用库里已存的 key。不写倍率表（with_sync=0），
  // 真正保存随「保存」按钮一起完成，避免没点保存就污染数据。
  App.aiChannelFetchModels=async function(btn){
    const id=parseInt($('#achId').value,10)||0;
    const url=$('#achUrl').value.trim();
    const key=$('#achKey').value.trim();
    const type=$('#achType').value;
    if(!url){ toast('请先填写接口地址 Base URL','warn'); $('#achUrl').focus(); return; }
    if(!key && !id){ toast('请先填写 API Key（保存前需用它鉴权拉取）','warn'); $('#achKey').focus(); return; }
    const hint=$('#achModelsHint');
    const oldHtml=btn?btn.innerHTML:'';
    if(btn){ btn.disabled=true; btn.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span>拉取中'; }
    if(hint){ hint.textContent='正在连接上游拉取模型列表…'; hint.className='form-text text-muted'; }
    try{
      const res=await POST('/api/aichannel/test',{ id:id, base_url:url, api_key:key, type:type, with_sync:0 });
      if(res.code!==0){
        if(hint){ hint.textContent='拉取失败：'+res.msg; hint.className='form-text text-danger'; }
        toast(res.msg,'err'); return;
      }
      const got=((res.data&&res.data.models)||[]).slice();
      // 与白名单里已有的手工项合并去重，保留已填项不被吞掉
      const cur=($('#achModels').value||'').split(/[,，\s]+/).map(function(s){return s.trim();}).filter(Boolean);
      const merged=cur.slice();
      got.forEach(function(m){ if(merged.indexOf(m)<0) merged.push(m); });
      merged.sort();
      $('#achModels').value=merged.join(',');
      if(hint){ hint.textContent='已拉取 '+got.length+' 个模型并回填白名单（共 '+merged.length+' 项），可手动删减后保存。'; hint.className='form-text text-success'; }
      toast('已拉取 '+got.length+' 个模型');
    }catch(e){
      if(hint){ hint.textContent='拉取异常：'+e.message; hint.className='form-text text-danger'; }
    }finally{
      if(btn){ btn.disabled=false; btn.innerHTML=oldHtml; }
    }
  };

  App.aiChannelTest=async function(id){
    const c=aihubState.channels.find(function(x){return x.id===id;}); if(!c) return;
    toast('正在测试连通性…');
    const res=await POST('/api/aichannel/test',{ id:id, base_url:c.base_url, with_sync:1 });
    if(res.code!==0){ toast(res.msg,'err'); return; }
    toast(res.msg + (res.data && res.data.added ? '，新登记 '+res.data.added+' 个模型' : ''));
    loadAihub();
  };

  App.aiChannelDel=async function(id){
    const c=aihubState.channels.find(function(x){return x.id===id;}); if(!c) return;
    if(!confirm('删除渠道「'+c.name+'」？')) return;
    const res=await POST('/api/aichannel/delete',{ id:id });
    if(res.code!==0){ toast(res.msg,'err'); return; }
    toast('已删除'); loadAihub();
  };

  // ---------- 模型倍率 ----------
  function renderAihubModel(body){
    const rows=aihubState.models.map(function(m){
      return `<tr>
        <td class="small">${esc(m.model_key)}</td>
        <td>${esc(m.display_name||'')}</td>
        <td>${m.prompt_ratio}</td>
        <td>${m.completion_ratio}</td>
        <td>${m.enabled?'<span class="badge bg-success">启用</span>':'<span class="badge bg-secondary">停用</span>'}</td>
        <td style="width:150px;white-space:nowrap">
          <button class="btn btn-sm btn-outline-primary" onclick="KS.aiModelForm(${m.id})">编辑</button>
          <button class="btn btn-sm btn-outline-danger" onclick="KS.aiModelDel(${m.id})">删</button>
        </td></tr>`;
    }).join('');
    body.innerHTML=`<div class="d-flex justify-content-between align-items-center mb-2">
      <div class="small text-muted">点数 = 输入 token × 输入倍率 + 输出 token × 输出倍率；未登记的模型按 1:1 计费。</div>
      <button class="btn btn-sm btn-primary" onclick="KS.aiModelForm(0)"><i class="bi bi-plus-lg"></i> 新增模型</button></div>
      <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr>
      <th>模型标识</th><th>显示名</th><th>输入倍率</th><th>输出倍率</th><th>状态</th><th>操作</th>
      </tr></thead><tbody>${rows||'<tr><td colspan="6" class="text-center text-muted py-4">暂无模型。可在「渠道 → 测试」里一键拉取并登记。</td></tr>'}</tbody></table></div>`;
  }

  App.aiModelForm=function(id){
    const m = id ? aihubState.models.find(function(x){return x.id===id;}) : null;
    openModal(id?'编辑模型倍率':'新增模型', `
      <input type="hidden" id="amdId" value="${id||0}">
      <div class="mb-2"><label class="form-label">模型标识（与上游模型名一致）</label><input id="amdKey" class="form-control" value="${m?esc(m.model_key):''}" placeholder="gpt-4o-mini"></div>
      <div class="mb-2"><label class="form-label">显示名</label><input id="amdName" class="form-control" value="${m?esc(m.display_name||''):''}" placeholder="可留空"></div>
      <div class="row g-2 mb-2">
        <div class="col-6"><label class="form-label">输入倍率</label><input id="amdP" type="number" step="0.0001" class="form-control" value="${m?m.prompt_ratio:1}"></div>
        <div class="col-6"><label class="form-label">输出倍率</label><input id="amdC" type="number" step="0.0001" class="form-control" value="${m?m.completion_ratio:1}"></div>
      </div>
      <div class="form-check"><input class="form-check-input" type="checkbox" id="amdEnabled" ${(!m||m.enabled)?'checked':''}><label class="form-check-label">启用</label></div>
    `, [{t:'保存',c:'btn-primary',act:saveAiModel}]);
  };

  async function saveAiModel(){
    const res=await POST('/api/aichannel/modelSave',{
      id: parseInt($('#amdId').value,10)||0,
      model_key: $('#amdKey').value.trim(),
      display_name: $('#amdName').value.trim(),
      prompt_ratio: parseFloat($('#amdP').value)||1,
      completion_ratio: parseFloat($('#amdC').value)||1,
      enabled: $('#amdEnabled').checked?1:0
    });
    if(res.code!==0){ toast(res.msg,'err'); return; }
    hideModal(); toast('已保存'); loadAihub();
  }

  App.aiModelDel=async function(id){
    if(!confirm('删除该模型倍率配置？')) return;
    const res=await POST('/api/aichannel/modelDelete',{ id:id });
    if(res.code!==0){ toast(res.msg,'err'); return; }
    toast('已删除'); loadAihub();
  };

  // ---------- 额度分配 ----------
  function renderAihubQuota(body){
    const rows=aihubState.quotas.map(function(q){
      const lim=function(v){ return v>0 ? esc(String(v)) : '<span class="text-muted">不限</span>'; };
      return `<tr>
        <td>${esc(q.name)}<div class="small text-muted">${esc(q.username)}${q.department?' · '+esc(q.department):''}</div></td>
        <td><b>${q.quota.balance}</b></td>
        <td class="small text-muted">${q.quota.total_used}</td>
        <td class="small">${lim(q.quota.daily_limit)}</td>
        <td class="small">${lim(q.quota.weekly_limit)}</td>
        <td class="small">${lim(q.quota.monthly_limit)}</td>
        <td style="width:110px"><button class="btn btn-sm btn-primary" onclick="KS.aiQuotaForm(${q.teacher_id})">分配</button></td>
      </tr>`;
    }).join('');
    body.innerHTML=`<div class="small text-muted mb-2">额度单位＝点数。周期上限填 0 表示不限。教师未分配额度时，站内 AI 助手不受限；AI 操练场与外部 API 一律严格校验。</div>
      <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr>
      <th>教师</th><th>余额</th><th>累计消耗</th><th>日上限</th><th>周上限</th><th>月上限</th><th>操作</th>
      </tr></thead><tbody>${rows||'<tr><td colspan="7" class="text-center text-muted py-4">暂无教师</td></tr>'}</tbody></table></div>`;
  }

  App.aiQuotaForm=function(tid){
    const q=aihubState.quotas.find(function(x){return x.teacher_id===tid;}); if(!q) return;
    openModal('分配 AI 额度 · '+q.name, `
      <div class="mb-2 small text-muted">当前余额 <b>${q.quota.balance}</b>，累计消耗 ${q.quota.total_used}</div>
      <div class="mb-3"><label class="form-label">充值 / 回收（正数充值，负数回收，0=只改上限）</label>
        <input id="aqAmount" type="number" step="0.01" class="form-control" value="0"></div>
      <div class="row g-2">
        <div class="col-4"><label class="form-label">日上限</label><input id="aqDaily" type="number" step="0.01" class="form-control" value="${q.quota.daily_limit}"></div>
        <div class="col-4"><label class="form-label">周上限</label><input id="aqWeekly" type="number" step="0.01" class="form-control" value="${q.quota.weekly_limit}"></div>
        <div class="col-4"><label class="form-label">月上限</label><input id="aqMonthly" type="number" step="0.01" class="form-control" value="${q.quota.monthly_limit}"></div>
      </div>
      <div class="small text-muted mt-2">上限填 0 表示该周期不限量</div>
    `, [{t:'保存',c:'btn-primary',act:function(){ return saveAiQuota(tid); }}]);
  };

  async function saveAiQuota(tid){
    const res=await POST('/api/aiquota/assign',{
      teacher_id: tid,
      amount: parseFloat($('#aqAmount').value)||0,
      daily_limit: parseFloat($('#aqDaily').value)||0,
      weekly_limit: parseFloat($('#aqWeekly').value)||0,
      monthly_limit: parseFloat($('#aqMonthly').value)||0
    });
    if(res.code!==0){ toast(res.msg,'err'); return; }
    hideModal(); toast('已保存'); loadAihub();
  }

  // -------- 学生管理（管理员 + 教师）--------
  let studentCache = { list: [], total: 0, page: 1 };

  ROUTERS['admin-students'] = function(host){
    const isAdmin = App.user && App.user.role === 'admin';
    host.innerHTML = `<div class="card">
      <div class="card-h"><i class="bi bi-people-fill text-primary"></i><span class="tt">学生管理</span>
        <div class="flex-grow-1"></div>
        <div class="btn-group btn-group-sm me-2" role="group">
          <button type="button" class="btn btn-outline-primary" id="stuTabAll" onclick="KS.stuTab('all')">全部学生</button>
          <button type="button" class="btn btn-outline-primary" id="stuTabPending" onclick="KS.stuTab('pending')">待审核</button>
        </div>
        ${isAdmin ? '<button class="btn btn-primary btn-sm" onclick="KS.studentForm(0)"><i class="bi bi-person-plus me-1"></i>新增学生</button>' : ''}
      </div>
      <div class="card-b">
        <div class="d-flex gap-2 mb-2 flex-wrap align-items-center">
          <input id="stuKeyword" class="form-control form-control-sm" style="width:200px" placeholder="搜索学号/姓名" onkeydown="if(event.key==='Enter')KS.loadStudents()">
          <select id="stuClassFilter" class="form-select form-select-sm" style="width:auto" onchange="KS.loadStudents()">
            <option value="0">全部班级</option>
          </select>
          <select id="stuStatusFilter" class="form-select form-select-sm" style="width:auto" onchange="KS.loadStudents()">
            <option value="">全部状态</option>
            <option value="1">正常</option>
            <option value="2">待审核</option>
            <option value="0">禁用</option>
          </select>
          <button class="btn btn-outline-secondary btn-sm" onclick="KS.loadStudents()"><i class="bi bi-search me-1"></i>查询</button>
          <div class="flex-grow-1"></div>
          <span class="small text-muted" id="stuTotalLabel">共 0 名学生</span>
        </div>
        <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr>
          <th>学号</th><th>姓名</th><th>班级</th><th>性别</th><th>入学年</th><th>状态</th><th>来源</th><th>最后登录</th><th style="width:190px">操作</th>
        </tr></thead><tbody id="stuTbody">
          <tr><td colspan="9" class="text-center text-muted py-4">加载中…</td></tr>
        </tbody></table></div>
        <nav id="stuPagination"></nav>
      </div>
    </div>`;
    loadClassSelect();
    KS.loadStudents();
  };

  KS.stuTab = function(tab){
    $('#stuTabAll').className = tab==='all' ? 'btn btn-primary btn-sm' : 'btn btn-outline-primary btn-sm';
    $('#stuTabPending').className = tab==='pending' ? 'btn btn-primary btn-sm' : 'btn btn-outline-primary btn-sm';
    if(tab==='pending'){ $('#stuStatusFilter').value='2'; } else { $('#stuStatusFilter').value=''; }
    KS.loadStudents();
  };

  KS.loadStudents = async function(page){
    page = page || 1;
    const keyword = ($('#stuKeyword').value||'').trim();
    const classId = ($('#stuClassFilter').value||'0');
    const status = ($('#stuStatusFilter').value||'');
    const q = 'page='+page;
    if(keyword) q+= '&keyword='+encodeURIComponent(keyword);
    if(classId && classId!='0') q+= '&class_id='+classId;
    if(status!=='') q+= '&status='+status;
    const res = await POST('/api/studentadmin/students?'+q);
    if(res.code!==0){ toast(res.msg,'err'); return; }
    studentCache = res.data;
    const rows = res.data.list||[];
    const tbody = $('#stuTbody');
    $('#stuTotalLabel').textContent = '共 '+(res.data.total||0)+' 名学生';
    if(!rows.length){
      tbody.innerHTML='<tr><td colspan="9" class="text-center text-muted py-4">暂无学生记录</td></tr>';
      $('#stuPagination').innerHTML='';
      return;
    }
    const isAdmin = App.user && App.user.role === 'admin';
    tbody.innerHTML = rows.map(function(s){
      const statusMap = { '0':'<span class="badge bg-secondary">禁用</span>', '1':'<span class="badge bg-success">正常</span>', '2':'<span class="badge bg-warning">待审核</span>' };
      const sourceMap = { 'import':'批量导入','register':'自助注册' };
      const genderMap = { '0':'—','1':'男','2':'女' };
      const ops = isAdmin ? (
        '<button class="btn btn-sm btn-outline-primary me-1" onclick="KS.studentForm('+s.id+')"><i class="bi bi-pencil"></i></button>'
        + (s.status==='2' ? '<button class="btn btn-sm btn-outline-success me-1" onclick="KS.approveStudent('+s.id+')">通过</button>' : '')
        + '<button class="btn btn-sm btn-outline-'+ (s.status==='1'?'danger':'success') +' me-1" onclick="KS.studentToggle('+s.id+','+(s.status==='1'?0:1)+')">'
        + (s.status==='1'?'禁用':'启用')+'</button>'
        + '<button class="btn btn-sm btn-outline-danger" onclick="KS.studentDelete('+s.id+')"><i class="bi bi-trash"></i></button>'
      ) : '<span class="text-muted small">只读</span>'; // 教师不可操作
      return '<tr><td>'+esc(s.sno)+'</td><td>'+esc(s.name)+'</td><td>'+esc(s.class_name)+'</td>'
        + '<td>'+genderMap[s.gender]+'</td><td>'+s.year+'</td>'
        + '<td>'+statusMap[s.status]+'</td><td>'+sourceMap[s.source]+'</td>'
        + '<td class="small">'+(s.last_login_at ? dateStr(s.last_login_at) : '—')+'</td>'
        + '<td>'+ops+'</td></tr>';
    }).join('');
    // 分页
    const totalPage = Math.ceil((res.data.total||0)/20);
    if(totalPage>1){
      let pgHtml='<ul class="pagination pagination-sm mb-0">';
      for(let i=1;i<=totalPage;i++){
        pgHtml+='<li class="page-item'+(i===page?' active':'')+'"><a class="page-link" href="#" onclick="event.preventDefault();KS.loadStudents('+i+')">'+i+'</a></li>';
      }
      pgHtml+='</ul>';
      $('#stuPagination').innerHTML=pgHtml;
    } else {
      $('#stuPagination').innerHTML='';
    }
  };

  KS.studentForm = async function(id){
    const isAdmin = App.user && App.user.role === 'admin';
    if(!isAdmin){ toast('仅管理员可操作','warn'); return; }
    let s = null;
    if(id>0){
      s = studentCache.list.find(function(x){return x.id===id;});
    }
    // 获取班级列表
    const clsRes = await POST('/api/admin/classes');
    const classOpts = (clsRes.code===0 && clsRes.data) ? clsRes.data : [];
    const classHtml = '<option value="0">无班级</option>'+classOpts.map(function(c){
      return '<option value="'+c.id+'"'+(s&&s.class_id===c.id?' selected':'')+'>'+esc(c.name)+'</option>';
    }).join('');
    openModal(id?'编辑学生':'新增学生', `
      <input type="hidden" id="stId" value="${id||0}">
      <div class="row g-2 mb-2">
        <div class="col-6"><label class="form-label">学号</label><input id="stSno" class="form-control" value="${s?esc(s.sno):''}" placeholder="如 2025001"></div>
        <div class="col-6"><label class="form-label">姓名</label><input id="stName" class="form-control" value="${s?esc(s.name):''}"></div>
      </div>
      <div class="row g-2 mb-2">
        <div class="col-4"><label class="form-label">性别</label><select id="stGender" class="form-select"><option value="0"${s&&s.gender===0?' selected':''}>未知</option><option value="1"${s&&s.gender===1?' selected':''}>男</option><option value="2"${s&&s.gender===2?' selected':''}>女</option></select></div>
        <div class="col-4"><label class="form-label">入学年</label><input id="stYear" type="number" class="form-control" value="${s?s.year:new Date().getFullYear()}" placeholder="${new Date().getFullYear()}"></div>
        <div class="col-4"><label class="form-label">电话</label><input id="stPhone" class="form-control" value="${s?esc(s.phone||''):''}"></div>
      </div>
      <div class="mb-2"><label class="form-label">班级</label><select id="stClass" class="form-select">${classHtml}</select></div>
      <div class="mb-2"><label class="form-label">密码${id?'（留空不修改）':''}</label><input id="stPwd" type="password" class="form-control" placeholder="${id?'留空保持原密码':'默认与学号相同'}"></div>
      <div class="form-check"><input class="form-check-input" type="checkbox" id="stStatus" ${!s||s.status==='1'?'checked':''}><label class="form-check-label">启用</label></div>
    `, [{t:'保存',c:'btn-primary',act:saveStudent}]);
  };

  async function saveStudent(){
    const id = parseInt($('#stId').value,10)||0;
    const res=await POST('/api/studentadmin/studentSave',{
      id, sno: $('#stSno').value.trim(), name: $('#stName').value.trim(),
      gender: parseInt($('#stGender').value,10)||0, year: parseInt($('#stYear').value,10)||0,
      phone: $('#stPhone').value.trim(), class_id: parseInt($('#stClass').value,10)||0,
      password: $('#stPwd').value, status: $('#stStatus').checked?1:0
    });
    if(res.code!==0){ toast(res.msg,'err'); return; }
    hideModal(); toast('已保存'); KS.loadStudents();
  }

  KS.studentToggle = async function(id,status){
    const res=await POST('/api/studentadmin/studentToggle',{ id:id, status:status });
    if(res.code!==0){ toast(res.msg,'err'); return; }
    toast(status?'已启用':'已禁用'); KS.loadStudents();
  };

  KS.studentDelete = async function(id){
    if(!confirm('确认删除该学生？相关的出勤和成绩记录不会被删除。')) return;
    const res=await POST('/api/studentadmin/studentDelete',{ id:id });
    if(res.code!==0){ toast(res.msg,'err'); return; }
    toast('已删除'); KS.loadStudents();
  };

  KS.approveStudent = async function(id){
    if(!confirm('审核通过该学生的注册申请？')) return;
    const res=await POST('/api/studentadmin/approveStudent',{ id:id });
    if(res.code!==0){ toast(res.msg,'err'); return; }
    toast('已审核通过'); KS.loadStudents();
  };

  async function loadClassSelect(){
    const res = await POST('/api/admin/classes');
    if(res.code!==0) return;
    const sel = $('#stuClassFilter');
    const cur = sel.value;
    sel.innerHTML = '<option value="0">全部班级</option>'
      + (res.data||[]).map(function(c){return '<option value="'+c.id+'"'+(c.id==parseInt(cur)?' selected':'')+'>'+esc(c.name)+'</option>';}).join('');
  }

  // -------- 基础配置（院系/课程/学期/系统参数） --------
  ROUTERS['admin-meta']=function(host){
    host.innerHTML=`<div class="row g-3">
      <div class="col-12 col-lg-6"><div class="card mb-3"><div class="card-h"><i class="bi bi-diagram-3 text-primary"></i><span class="tt">院系管理</span>
        <div class="flex-grow-1"></div><button class="btn btn-sm btn-primary" onclick="KS.deptForm()">新增院系</button></div>
        <div class="table-responsive"><table class="table"><thead><tr><th>院系</th><th>教师数</th><th style="width:90px">操作</th></tr></thead><tbody id="deptTbody"></tbody></table></div></div>
        <div class="card mb-3"><div class="card-h"><i class="bi bi-people text-primary"></i><span class="tt">班级管理</span>
          <div class="flex-grow-1"></div><button class="btn btn-sm btn-primary" onclick="KS.classForm()">新增班级</button></div>
          <div class="table-responsive" style="max-height:340px;overflow:auto"><table class="table"><thead><tr><th>班级</th><th>所属院系</th><th>年级</th><th>状态</th><th>引用</th><th style="width:90px">操作</th></tr></thead><tbody id="classTbody"></tbody></table></div></div>
        <div class="card mb-3"><div class="card-h"><i class="bi bi-collection text-primary"></i><span class="tt">学期配置</span>
          <div class="flex-grow-1"></div><button class="btn btn-sm btn-primary" onclick="KS.termForm()">新增学期</button></div>
          <div class="table-responsive"><table class="table"><thead><tr><th>学期</th><th>周次</th><th>开学日</th><th>课时</th><th style="width:180px">操作</th></tr></thead><tbody id="termTbody"></tbody></table></div></div></div>
      <div class="col-12 col-lg-6"><div class="card mb-3"><div class="card-h"><i class="bi bi-book text-primary"></i><span class="tt">全校课程库</span>
        <div class="flex-grow-1"></div><button class="btn btn-sm btn-primary" onclick="KS.adminCourseForm()">新增课程</button></div>
        <div class="table-responsive" style="max-height:340px;overflow:auto"><table class="table"><thead><tr><th>课程</th><th>归属</th><th>单价</th><th>班级</th><th>引用</th><th style="width:110px">操作</th></tr></thead><tbody id="adminCourseTbody"></tbody></table></div></div>
        <div class="card"><div class="card-h"><i class="bi bi-sliders text-primary"></i><span class="tt">系统参数</span></div>
        <div class="card-b"><div class="mb-3"><label class="form-label">学校名称</label><input id="cfSchool" class="form-control"></div>
        <div class="row g-2">
          <div class="col-6"><label class="form-label">全局课时单价(元/节)</label><input id="cfPrice" type="number" class="form-control"></div>
          <div class="col-6"><label class="form-label">周标准课时</label><input id="cfStd" type="number" class="form-control"></div>
        </div>
        <div class="row g-2 mt-0"><div class="col-6"><label class="form-label">AI 总开关</label><select id="cfAi" class="form-select"><option value="1">开启</option><option value="0">关闭</option></select></div>
        <div class="col-6"><label class="form-label">AI 引擎标识</label><input id="cfProv" class="form-control" placeholder="openai-compatible"></div></div>
        <div class="mb-2"><label class="form-label">系统模型接口地址</label><input id="cfUrl" class="form-control" placeholder="https://api.example.com/v1/chat/completions"></div>
        <div class="row g-2"><div class="col-6"><label class="form-label">系统 API Key</label><input id="cfKey" type="password" class="form-control" placeholder="留空表示保留原 Key"></div>
        <div class="col-6"><label class="form-label">系统模型名称</label><div class="input-group"><input id="cfModel" class="form-control" placeholder="如：gpt-4o-mini" list="cfModelList"><button class="btn btn-outline-secondary" type="button" onclick="KS.fetchSysModels()" title="根据接口地址和 Key 自动拉取模型列表"><i class="bi bi-arrow-down-circle me-1"></i>拉取</button></div><datalist id="cfModelList"></datalist></div></div>
        <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="cfKeyClear"><label class="form-check-label" for="cfKeyClear">清除已保存的系统 Key</label></div>
        <div class="small text-muted mt-2" id="cfKeyStatus">系统 Key 状态：读取中…</div>
        <div class="mt-3"><label class="form-label">在线更新源</label>
          <select id="cfUpdateSource" class="form-select" onchange="App.onUpdateSourceChange()">
            <option value="github">GitHub Releases（默认推荐）</option>
            <option value="cnb">CNB 官方 Release（v1.0.1 兼容通道）</option>
            <option value="custom">自定义（自填 manifest URL）</option>
          </select>
          <div class="small text-muted mt-1">切换后会立即接管「系统更新」页的检查/下载源，GitHub 私有仓库需配合下方 Token。</div>
        </div>
        <div class="mb-2 mt-3"><label class="form-label">更新清单地址 (manifest.json)</label>
          <input id="cfUpdateUrl" class="form-control" placeholder="https://update.example.com/manifest.json（需 https，支持签名校验）"></div>
        <div class="row g-2"><div class="col-8"><label class="form-label">GitHub Personal Access Token（可选）</label><input id="cfGhToken" type="password" class="form-control" placeholder="留空可访问公开仓库，私有仓库或提升限流时填写"></div>
        <div class="col-4 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" id="cfGhTokenClear"><label class="form-check-label" for="cfGhTokenClear">清除 Token</label></div></div></div>
        <div class="small text-muted" id="cfGhTokenStatus">GitHub Token 状态：读取中…</div>
        <button class="btn btn-primary mt-2" onclick="KS.saveSettings()"><i class="bi bi-check me-1"></i>保存系统参数</button></div>
        <div class="card mt-3 border-danger"><div class="card-h bg-danger bg-opacity-10"><i class="bi bi-exclamation-octagon text-danger"></i><span class="tt text-danger">危险操作</span></div>
        <div class="card-b">
          <div class="mb-2">一键重置为系统示例数据：<b>清空全部业务表</b>（课时/班级/AI 会话/操作日志），<b>仅保留</b>：
            <ul class="mb-2 small">
              <li>所有管理员账号（admin 至少保留 1 个，密码重置为 admin123）</li>
              <li>系统配置（ks_setting：学校名称、单价、AI 等基础项）</li>
              <li>院系、学期、教师中的非 admin</li>
              <li>1 门示范课程：<b>工业机器人导论</b>（便于教学展示）</li>
            </ul>
          </div>
          <button class="btn btn-outline-danger" onclick="KS.resetDemoData()"><i class="bi bi-arrow-counterclockwise me-1"></i>一键重置示例数据</button>
        </div></div>
        </div></div>
    </div>`;
    loadDeptsTable(); loadClassesTable(); loadTermsTable(); loadAdminCoursesTable(); loadSettingsForm();
  };
  App.onUpdateSourceChange=function(){
    const v=$('#cfUpdateSource').value;
    const hint={
      github:'切换为 GitHub Releases（推荐）。如未手动填过清单地址，将自动使用默认 GitHub 资产 URL。',
      cnb:'切换为 CNB 官方 Release（v1.0.1 兼容通道）。',
      custom:'切换为自定义：必须手动填写「更新清单地址」才可使用。'
    }[v] || '';
    // 仅做文案提示
    const el=$('#cfGhTokenStatus');
    if(el) el.textContent='GitHub Token 状态：'+(v==='github'?'（建议配置公开仓库可留空）':'（仅 GitHub 源需要）') + '　提示：' + hint;
  };
  async function loadClassesTable(){
    const tb=$('#classTbody'); if(!tb)return;
    const [clsRes, deptRes]=await Promise.all([GET('/api/admin/classes'), GET('/api/admin/departments')]);
    if(clsRes.code!==0)return;
    if (gone('classTbody')) return;
    const list=clsRes.data||[];
    const depts=((deptRes.code===0)?(deptRes.data||[]):[]);
    tb.innerHTML=list.length?list.map(c=>{
      const dept=depts.find(d=>d.id===c.department_id);
      const deptName=dept?dept.name:'—';
      return '<tr><td><b>'+esc(c.name)+'</b></td>'
        +'<td>'+esc(deptName)+'</td>'
        +'<td>'+(c.year||'—')+'</td>'
        +'<td>'+(c.status?'<span class="badge bg-success">启用</span>':'<span class="badge bg-secondary">停用</span>')+'</td>'
        +'<td class="text-end">'+c.lesson_count+'</td>'
        +'<td><button class="btn btn-sm btn-outline-secondary py-0" onclick="KS.classForm('+c.id+')">编辑</button> '
        +'<button class="btn btn-sm btn-outline-danger py-0" onclick="KS.classDel('+c.id+','+jsStr(c.name)+')">删</button></td></tr>';
    }).join(''):'<tr><td colspan="6"><div class="dk-empty"><i class="bi bi-people"></i>暂无班级</div></td></tr>';
  }
  App.classForm=async function(id){
    const [clsRes, deptRes]=await Promise.all([id?GET('/api/admin/classes'):Promise.resolve({code:0,data:[]}), GET('/api/admin/departments')]);
    const list=clsRes.code===0?(clsRes.data||[]):[];
    const c=id?list.find(x=>x.id===id):null;
    const depts=(deptRes.code===0)?(deptRes.data||[]):[];
    const deptOpts=depts.map(d=>'<option value="'+d.id+'"'+(c&&c.department_id===d.id?' selected':'')+'>'+esc(d.name)+'</option>').join('');
    openModal('班级', `<div class="row g-2"><div class="col-12"><label class="form-label">班级名称</label><input id="clName" class="form-control" value="${c?esc(c.name):''}"></div>
      <div class="col-7"><label class="form-label">所属院系</label><select id="clDept" class="form-select"><option value="0">未指定</option>${deptOpts}</select></div>
      <div class="col-5"><label class="form-label">入学年份</label><input id="clYear" type="number" class="form-control" value="${c?(c.year||''):new Date().getFullYear()-1}"></div>
      <div class="col-6"><label class="form-label">排序</label><input id="clSort" type="number" class="form-control" value="${c?c.sort:0}"></div>
      <div class="col-6"><label class="form-label">状态</label><select id="clStatus" class="form-select"><option value="1" ${(!c||c.status===1)?'selected':''}>启用</option><option value="0" ${(c&&c.status===0)?'selected':''}>停用</option></select></div>
      <div class="col-12"><label class="form-label">备注</label><input id="clRemark" class="form-control" value="${c?esc(c.remark||''):''}"></div></div>`,
      [{t:'取消',c:'btn-light',x:true},{t:'保存',c:'btn-primary',act:()=>KS.classSave(id)}]);
  };
  App.classSave=async function(id){
    const payload={name:$('#clName').value.trim(),department_id:parseInt($('#clDept').value||0,10),year:parseInt($('#clYear').value||0,10),status:parseInt($('#clStatus').value||1,10),sort:parseInt($('#clSort').value||0,10),remark:$('#clRemark').value.trim()};
    if(!payload.name){toast('请填名称','warn');return;}
    if(id)payload.id=id;
    const res=await POST('/api/admin/classSave',payload);
    if(res.code===0){toast('已保存');hideModal();loadClassesTable();refreshBootClasses();}else toast(res.msg,'err');
  };
  App.classDel=async function(id,name){
    if(!confirm('删除班级 '+name+'？\n（已引用课时的班级仍可删，ks_lesson.classes 是文本快照）'))return;
    const res=await POST('/api/admin/classDelete',{id});
    if(res.code===0){toast('已删除');loadClassesTable();refreshBootClasses();}else toast(res.msg,'err');
  };
  async function refreshBootClasses(){
    // 重新拉 boot 拿最新 classes，刷新前端 App.boot.classes
    const res=await GET('/api/auth/me');
    if(res.code===0){ App.boot=res.data.boot; }
  }
  async function loadDeptsTable(){
    const tb=$('#deptTbody'); if(!tb)return;
    const res=await GET('/api/admin/departments');
    if(res.code!==0)return;
    if (gone('deptTbody')) return;
    const list=res.data||[];
    tb.innerHTML=list.map(d=>'<tr><td><b>'+esc(d.name)+'</b></td><td>'+d.teacher_count+'</td>'
      +'<td><button class="btn btn-sm btn-outline-secondary py-0" onclick="KS.deptForm('+d.id+')">编辑</button> '
      +'<button class="btn btn-sm btn-outline-danger py-0" onclick="KS.deptDel('+d.id+','+jsStr(d.name)+')">删</button></td></tr>').join('')
      || '<tr><td colspan="3" class="text-center text-muted">暂无院系</td></tr>';
  }
  App.deptForm=async function(id){
    let d=null;
    if(id){ const res=await GET('/api/admin/departments'); d=(res.code===0?(res.data||[]):[]).find(x=>x.id===id)||null; }
    openModal(d?'编辑院系':'新增院系','<div class="mb-2"><label class="form-label">院系名称</label><input id="dpName" class="form-control" value="'+(d?esc(d.name):'')+'"></div>'
      +'<div class="mb-2"><label class="form-label">排序</label><input id="dpSort" type="number" class="form-control" value="'+(d?d.sort:0)+'"></div>',
      [{t:'取消',c:'btn-light',x:true},{t:'保存',c:'btn-primary',act:()=>KS.deptSave(id)}]);
  };
  App.deptSave=async function(id){ const n=$('#dpName').value.trim(); if(!n){toast('请填名称','warn');return;} const payload={name:n,sort:parseInt($('#dpSort').value||0,10)}; if(id)payload.id=id; const res=await POST('/api/admin/departmentSave',payload); res.code===0?toast('已保存'):toast(res.msg,'err'); if(res.code===0){hideModal();loadDeptsTable();} };
  App.deptDel=async function(id,name){ if(!confirm('删除院系 '+name+'？'))return; const res=await POST('/api/admin/departmentDelete',{id}); res.code===0?(toast('已删除'),loadDeptsTable()):toast(res.msg,'err'); };
  async function loadTermsTable(){
    const tb=$('#termTbody'); if(!tb)return;
    const res=await GET('/api/admin/terms');
    if(res.code!==0)return;
    if (gone('termTbody')) return;
    const list=res.data||[];
    tb.innerHTML=list.map(t=>'<tr><td><b>'+esc(t.name)+'</b>'+(t.is_current?' <span class="badge bg-primary">当前</span>':'')+'</td>'
      +'<td>第'+t.start_week+'-'+t.end_week+'周</td><td class="small">'+(t.start_date||'—')+'</td><td>'+t.lesson_count+'</td>'
      +'<td>'+(t.is_current?'':'<button class="btn btn-sm btn-outline-primary py-0" onclick="KS.termSet('+t.id+')">设为当前</button> ')
      +'<button class="btn btn-sm btn-outline-secondary py-0" onclick="KS.termForm('+t.id+')">编辑</button></td></tr>').join('');
  }
  App.termForm=async function(id){
    const list=(await GET('/api/admin/terms')).data||[];
    const t=list.find(x=>x.id==id)||null;
    openModal(t?'编辑学期':'新增学期', `
      <div class="mb-2"><label class="form-label">学期名称</label><input id="tmName" class="form-control" value="${t?esc(t.name):''}" placeholder="如 2026-2027学年第一学期"></div>
      <div class="row g-2">
        <div class="col-4"><label class="form-label">起始周</label><input id="tmS" type="number" value="${t?t.start_week:1}" class="form-control"></div>
        <div class="col-4"><label class="form-label">结束周</label><input id="tmE" type="number" value="${t?t.end_week:20}" class="form-control"></div>
        <div class="col-4"><label class="form-label">开学日期</label><input id="tmD" type="date" class="form-control" value="${t&&t.start_date?t.start_date:''}"></div>
      </div>
      <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="tmCur" ${t&&t.is_current?'checked':''}><label class="form-check-label" for="tmCur">设为当前学期</label></div>`,
      [{t:'取消',c:'btn-light',x:true},{t:'保存',c:'btn-primary',act:()=>KS.termSave(t?t.id:0)}]);
  };
  App.termSave=async function(id){
    const payload={ id:id||undefined, name:$('#tmName').value.trim(), start_week:parseInt($('#tmS').value,10), end_week:parseInt($('#tmE').value,10), start_date:$('#tmD').value||'', is_current:$('#tmCur').checked?1:0 };
    if(!payload.name){toast('请填学期名','warn');return;}
    const res=await POST('/api/admin/termSave',payload);
    if(res.code===0){toast('已保存');hideModal();loadTermsTable();refreshBootTerms();} else toast(res.msg,'err');
  };
  App.termSet=async function(id){ const res=await POST('/api/admin/termSetCurrent',{id}); if(res.code===0){toast('已设为当前');loadTermsTable();refreshBootTerms();} else toast(res.msg,'err'); };
  async function refreshBootTerms(){ const res=await GET('/api/auth/me'); if(res.code===0){ App.user=res.data.user; App.boot=res.data.boot; } }
  async function loadAdminCoursesTable(){
    const tb=$('#adminCourseTbody'); if(!tb)return;
    const res=await GET('/api/admin/courses');
    if(res.code!==0)return;
    if (gone('adminCourseTbody')) return;
    const list=res.data||[];
    tb.innerHTML=list.map(c=>'<tr><td><b>'+esc(c.name)+'</b></td><td class="small">'+esc(c.teacher||'全校公共')+'</td><td>¥'+Number(c.price).toFixed(2)+'</td>'
      +'<td class="small">'+esc(c.classes)+'</td><td>'+c.lesson_count+'</td>'
      +'<td><button class="btn btn-sm btn-outline-secondary py-0" onclick="KS.adminCourseForm('+c.id+')">编辑</button> '
      +'<button class="btn btn-sm btn-outline-danger py-0" onclick="KS.adminCourseDel('+c.id+','+jsStr(c.name)+')">删</button></td></tr>').join('');
  }
  App.adminCourseForm=async function(id){
    let c=null;
    if(id){ const res=await GET('/api/admin/courses'); c=(res.code===0?(res.data||[]):[]).find(x=>x.id===id)||null; }
    openModal(c?'编辑课程':'新增公共课程', `
      <div class="mb-2"><label class="form-label">课程名称</label><input id="acName" class="form-control" value="${c?esc(c.name):''}"></div>
      <div class="mb-2"><label class="form-label">默认班级</label><input id="acClasses" class="form-control" placeholder="多班逗号分隔" value="${c?esc(c.classes||''):''}"></div>
      <div class="mb-2"><label class="form-label">课时单价(元/节)</label><input id="acPrice" type="number" class="form-control" value="${c?c.price:''}"></div>`,
      [{t:'取消',c:'btn-light',x:true},{t:'保存',c:'btn-primary',act:()=>KS.adminCourseSave(id, c?c.teacher_id:0)}]);
  };
  App.adminCourseSave=async function(id, teacherId){
    const payload={ name:$('#acName').value.trim(), classes:$('#acClasses').value.trim(), price:parseFloat($('#acPrice').value||0), is_public:1 };
    if(!payload.name){toast('请填课程名','warn');return;}
    if(id){ payload.id=id; payload.teacher_id=teacherId||0; }
    const res=await POST('/api/admin/courseSave',payload);
    if(res.code===0){toast('已保存');hideModal();loadAdminCoursesTable();refreshBootTerms();} else toast(res.msg,'err');
  };
  App.adminCourseDel=async function(id,name){ if(!confirm('删除课程 '+name+'？'))return; const res=await POST('/api/admin/courseDelete',{id}); if(res.code===0){toast('已删除');loadAdminCoursesTable();} else toast(res.msg,'err'); };
  async function loadSettingsForm(){
    if (!$('#cfSchool')) return; // 页面已被切走
    const res=await GET('/api/admin/settings');
    if(res.code!==0)return;
    if (!$('#cfSchool')) return;
    const s=res.data||{};
    $('#cfSchool').value=s.school_name||''; $('#cfPrice').value=s.global_price||''; $('#cfStd').value=s.week_standard_periods||'';
    $('#cfAi').value=String(s.ai_enabled==null?'1':s.ai_enabled); $('#cfProv').value=s.ai_provider||'openai-compatible';
    $('#cfUrl').value=s.ai_api_url||''; $('#cfKey').value=''; $('#cfModel').value=s.ai_model||'';
    $('#cfKeyStatus').textContent='系统 Key 状态：'+(s.ai_api_key_configured?'已配置（页面不显示明文）':'未配置');
    const updEl=$('#cfUpdateUrl'); if(updEl) updEl.value=s.update_manifest_url||'';
    const srcEl=$('#cfUpdateSource'); if(srcEl){ srcEl.value=s.update_source||'github'; App.onUpdateSourceChange(); }
    const ghEl=$('#cfGhTokenStatus'); if(ghEl) ghEl.textContent='GitHub Token 状态：'+(s.update_github_token_configured?'已配置（页面不显示明文）':'未配置');
  }
  App.fetchSysModels=async function(){
    const url=$('#cfUrl').value.trim(), key=$('#cfKey').value.trim();
    if(!url){toast('请先填写系统模型接口地址','warn');return;}
    toast('正在拉取模型列表…');
    const res=await POST('/api/admin/aiModels',{api_url:url,api_key:key});
    if(res.code!==0){toast(res.msg,'err');return;}
    const models=(res.data&&res.data.models)||[];
    const dl=$('#cfModelList'); if(!dl)return;
    dl.innerHTML=models.map(m=>'<option value="'+esc(m)+'">').join('');
    const inp=$('#cfModel');
    if(inp && !inp.value && models.length) inp.value=models[0];
    toast('已拉取 '+models.length+' 个模型，点击系统模型名称输入框可下拉选择');
    if(inp) inp.focus();
  };
  App.saveSettings=async function(){
    const payload={ school_name:$('#cfSchool').value.trim(), global_price:$('#cfPrice').value, week_standard_periods:$('#cfStd').value,
      ai_enabled:$('#cfAi').value, ai_provider:$('#cfProv').value.trim(), ai_api_url:$('#cfUrl').value.trim(), ai_model:$('#cfModel').value.trim(),
      ai_api_key_clear:$('#cfKeyClear').checked?1:0,
      update_manifest_url: ($('#cfUpdateUrl')?$('#cfUpdateUrl').value.trim():''),
      update_source: ($('#cfUpdateSource')?$('#cfUpdateSource').value:'github'),
      update_github_token_clear:$('#cfGhTokenClear')?($('#cfGhTokenClear').checked?1:0):0
    };
    const key=$('#cfKey').value.trim();
    if(key) payload.ai_api_key=key;
    const ghToken=($('#cfGhToken')?$('#cfGhToken').value.trim():'');
    if(ghToken) payload.update_github_token=ghToken;
    const res=await POST('/api/admin/settingsSave',payload);
    if(res.code===0){toast('系统参数已保存'); refreshBootTerms(); loadSettingsForm();} else toast(res.msg,'err');
  };

  /**
   * 一键重置示例数据：弹窗 → 输入 RESET + 管理员密码 → 二次确认
   */
  App.resetDemoData=async function(){
    // 先做一次预演
    let pre;
    try {
      pre=await GET('/api/admin/resetDemoDataPreview');
    } catch (e) {
      toast('无法获取重置预览：' + (e && e.message ? e.message : '接口请求失败'),'err');
      return;
    }
    if(pre.code!==0){toast(pre.msg||'无法获取重置预览','err');return;}
    const p=pre.data||{};
    if(!p.admin_count){toast('当前没有可用的管理员账号，重置已拒绝','err');return;}
    const html=`<div class="alert alert-danger small"><i class="bi bi-exclamation-triangle me-1"></i>此操作会<b>立即清空</b>以下业务表：</div>
      <ul class="small">
        <li>课时记录：<b>${p.tables.ks_lesson||0}</b> 条</li>
        <li>班级：<b>${p.tables.ks_class||0}</b> 个</li>
        <li>课程（非示范）：<b>${p.tables.ks_course||0}</b> 门</li>
        <li>AI 会话 / 消息 / 配置：${(p.tables.ks_ai_conversation||0)+(p.tables.ks_ai_message||0)+(p.tables.ks_teacher_ai_config||0)} 条</li>
        <li>操作日志：<b>${p.tables.ks_operation_log||0}</b> 条</li>
        <li>常用课程收藏：<b>${p.tables.ks_course_favorite||0}</b> 条</li>
      </ul>
      <div class="alert alert-info small"><i class="bi bi-info-circle me-1"></i>将保留：管理员账号（${p.admin_count} 个）、院系/学期、所有非 admin 教师、1 门示范课程（${p.demo_course_name||'工业机器人导论'}）。</div>
      <div class="mb-2"><label class="form-label">请输入 <code>RESET</code> 二次确认</label><input id="rdConfirm" class="form-control" placeholder="RESET"></div>
      <div class="mb-2"><label class="form-label">请输入当前管理员密码</label><input id="rdPwd" type="password" class="form-control" placeholder="管理员登录密码"></div>`;
    openModal('一键重置示例数据（不可恢复）', html, [
      {t:'取消', c:'btn-light', x:true},
      {t:'确认重置', c:'btn-danger', act: async ()=>{
        const confirmText=($('#rdConfirm').value||'').trim();
        const pwd=($('#rdPwd').value||'').trim();
        if(confirmText!=='RESET'){toast('请输入 RESET','warn');return;}
        if(!pwd){toast('请输入管理员密码','warn');return;}
        if(!window.confirm('最后一次确认：立即重置为示例数据吗？此操作不可撤销！'))return;
        toast('正在重置…');
        const r=await POST('/api/admin/resetDemoData',{confirm,password:pwd});
        if(r.code===0){
          toast(r.msg||'重置完成');
          hideModal();
          // 刷新相关数据
          if(typeof loadDeptsTable==='function')loadDeptsTable();
          if(typeof loadClassesTable==='function')loadClassesTable();
          if(typeof loadTermsTable==='function')loadTermsTable();
          if(typeof loadAdminCoursesTable==='function')loadAdminCoursesTable();
          if(typeof loadSettingsForm==='function')loadSettingsForm();
          if(typeof refreshBootTerms==='function')refreshBootTerms();
          if(typeof refreshBootClasses==='function')refreshBootClasses();
        } else {
          toast(r.msg||'重置失败','err');
        }
      }}
    ]);
  };

  // -------- 月度课酬对账（管理员） --------
  ROUTERS['admin-reconcile']=function(host){
    const terms = App.boot.terms || [];
    const termOpts = terms.map(t=>'<option value="'+t.id+'"'+(t.is_current===1?' selected':'')+'>'+esc(t.name)+'</option>').join('');
    host.innerHTML = '<div class="card"><div class="card-h"><i class="bi bi-cash-stack text-primary"></i><span class="tt">月度课酬对账表</span>'
      + '<div class="flex-grow-1"></div>'
      + '<select id="rcTerm" class="form-select form-select-sm" style="width:auto" onchange="KS.refreshReconcile()">' + termOpts + '</select>'
      + '<button class="btn btn-outline-success btn-sm" onclick="KS.exportReconcile()"><i class="bi bi-download me-1"></i>导出 CSV</button>'
      + '</div>'
      + '<div class="card-b small text-secondary" style="padding-bottom:0">按实际授课日期归月；未填授课日期的课时不计入（见工作台「待处理」）。</div>'
      + '<div class="table-responsive" style="max-height:calc(100vh - 260px);overflow:auto">'
      + '<table class="table table-sm table-hover align-middle mb-0" id="rcTable"><thead></thead><tbody id="rcBody"></tbody><tfoot id="rcFoot"></tfoot></table></div></div>';
    loadReconcile();
  };
  App.refreshReconcile = function(){ loadReconcile(); };
  async function loadReconcile(){
    const tb=$('#rcBody'), thd=$('#rcTable thead'), ft=$('#rcFoot'); if(!tb) return;
    const termId = $('#rcTerm') ? $('#rcTerm').value : '';
    thd.innerHTML=''; tb.innerHTML='<tr><td class="text-center text-muted py-4">加载中…</td></tr>'; ft.innerHTML='';
    let d;
    try {
      const res = await GET('/api/stat/reconcile', termId ? { term_id: termId } : null);
      if (res.code !== 0) { tb.innerHTML='<tr><td class="text-center text-danger py-4">'+esc(res.msg)+'</td></tr>'; return; }
      d = res.data;
    } catch (e) { tb.innerHTML='<tr><td class="text-center text-danger py-4">加载失败</td></tr>'; return; }
    if (gone('rcBody')) return;
    const months = d.months || [], teachers = d.teachers || [], total = d.total || {};
    // 表头：教师 | 各月(节/¥) | 学期合计
    let h = '<tr class="sticky-top"><th style="min-width:120px" rowspan="2">教师</th><th rowspan="2">院系</th>';
    months.forEach(m => { h += '<th class="text-center" colspan="2" style="min-width:130px">'+m.slice(5)+'月</th>'; });
    h += '<th class="text-end" rowspan="2" style="min-width:90px">合计课时</th><th class="text-end" rowspan="2" style="min-width:110px">合计课酬</th></tr>';
    h += '<tr class="sticky-top" style="top:28px">';
    months.forEach(()=>{ h += '<th class="text-end small">节</th><th class="text-end small">¥</th>'; });
    h += '</tr>';
    thd.innerHTML=h;
    if (!teachers.length) { tb.innerHTML='<tr><td colspan="'+(3+months.length*2)+'"><div class="dk-empty"><i class="bi bi-inbox"></i>本学期暂无已填日期的课时</div></td></tr>'; return; }
    tb.innerHTML=teachers.map(t => {
      let row = '<tr><td><b>'+esc(t.name)+'</b></td><td class="small text-muted">'+esc(t.department)+'</td>';
      months.forEach(m => {
        const c = t.months[m];
        if (c) row += '<td class="text-end">'+c.periods+'</td><td class="text-end" style="color:#059669;font-weight:600">'+fmtMoney(c.amount)+'</td>';
        else row += '<td class="text-end text-muted">—</td><td class="text-end text-muted">—</td>';
      });
      row += '<td class="text-end fw-bold">'+t.total.periods+'</td><td class="text-end fw-bold" style="color:#059669">¥'+fmtMoney(t.total.amount)+'</td></tr>';
      return row;
    }).join('');
    // 合计行
    let f = '<tr class="table-light fw-bold"><td>合计</td><td>'+teachers.length+' 人</td>';
    months.forEach(m => {
      let p=0, a=0;
      teachers.forEach(t => { if (t.months[m]) { p += t.months[m].periods; a += t.months[m].amount; } });
      f += '<td class="text-end">'+(p||'—')+'</td><td class="text-end" style="color:#059669">'+(a?'¥'+fmtMoney(a):'—')+'</td>';
    });
    f += '<td class="text-end">'+total.periods+'</td><td class="text-end" style="color:#059669">¥'+fmtMoney(total.amount)+'</td></tr>';
    ft.innerHTML=f;
  }
  App.exportReconcile = function(){
    const term = curTerm();
    const q = [];
    if (term) q.push('term_id=' + term.id);
    downloadCSV('/api/export/reconcile' + (q.length ? '?' + q.join('&') : '') + '&t=' + Date.now());
  };

  // -------- 系统更新 --------
  ROUTERS['admin-update']=function(host){
    host.innerHTML=`
    <div class="row g-3">
      <div class="col-12 col-lg-7">
        <div class="card mb-3"><div class="card-h"><i class="bi bi-arrow-repeat text-primary"></i><span class="tt">系统在线更新</span>
          <div class="flex-grow-1"></div>
          <button class="btn btn-sm btn-link text-muted p-0" title="重新检查" onclick="KS.updateCheck()"><i class="bi bi-arrow-clockwise"></i></button>
        </div>
        <div class="card-b">
          <!-- v1.1.2：版本主卡片。进页面自动检查，有新版直接出横幅 + 立即更新按钮 -->
          <div class="text-center py-2">
            <div class="small text-muted">当前版本</div>
            <div class="display-6 fw-bold lh-1 my-1" id="udCurrent">—</div>
            <div class="small text-muted">最新版本：<span id="udLatest">检查中…</span></div>
          </div>
          <div class="small text-danger text-center d-none mb-2" id="udLastErr" style="word-break:break-all"></div>

          <!-- 有新版本时显示 -->
          <div id="udNewBox" class="d-none">
            <div class="alert alert-warning d-flex align-items-center gap-2 py-2 mb-2">
              <i class="bi bi-download fs-5"></i>
              <div><b>有新版本可用！</b><div class="small" id="udNewVer"></div></div>
            </div>
            <button class="btn btn-primary btn-lg w-100 mb-2" onclick="KS.updateInstall()">
              <i class="bi bi-download me-1"></i>立即更新
            </button>
          </div>
          <!-- 已是最新时显示 -->
          <div id="udOkBox" class="d-none">
            <div class="alert alert-success d-flex align-items-center gap-2 py-2 mb-2">
              <i class="bi bi-check-circle fs-5"></i><div>已是最新版本</div>
            </div>
          </div>

          <div class="text-center mb-2">
            <a href="javascript:;" class="small text-decoration-none" onclick="KS.updateToggleAdv()">
              <span id="udAdvLabel">查看更新日志与高级选项</span> <i class="bi bi-chevron-down"></i>
            </a>
          </div>

          <!-- 高级选项：默认折叠，普通用户不用碰 -->
          <div id="udAdv" class="d-none border-top pt-3">
            <div class="small text-muted mb-2">代码基线 <span id="udBaseline">—</span>　·　PHP <span id="udPhpVer">—</span>　·　<span id="udLastCheck"></span></div>
            <div class="mb-3"><label class="form-label">更新清单地址 (manifest.json)</label>
              <input id="udManifest" class="form-control form-control-sm" placeholder="留空则使用系统设置里的默认更新源">
              <div class="form-text">一般无需填写。留空时自动使用「系统设置 → 在线更新源」配置的地址。</div></div>
            <div class="small text-muted mb-2" id="udChangelog"></div>
            <div class="alert alert-warning py-2 small mb-2">更新会先备份当前文件与整库，升级失败会自动回滚。升级期间系统进入极短维护模式，请勿刷新或关闭页面。</div>
            <div class="d-flex gap-2 flex-wrap">
              <button class="btn btn-sm btn-outline-secondary" onclick="KS.updateRefresh()"><i class="bi bi-arrow-clockwise me-1"></i>读取状态</button>
              <button class="btn btn-sm btn-outline-primary" onclick="KS.updateCheck()"><i class="bi bi-search me-1"></i>检查更新</button>
              <button class="btn btn-sm btn-outline-primary" onclick="KS.updateInstall()"><i class="bi bi-rocket-takeoff me-1"></i>一键升级</button>
              <button class="btn btn-sm btn-outline-danger ms-auto" onclick="KS.maintenanceToggle()"><i class="bi bi-moon-stars me-1"></i><span id="udMaintBtn">维护模式</span></button>
            </div>
            <div class="small text-muted mt-2" id="udSourceHint">更新源：—</div>
          </div>
          <div id="udResult" class="mt-3"></div>
        </div></div>
        <div class="card mb-3"><div class="card-h"><i class="bi bi-archive text-primary"></i><span class="tt">备份与回滚</span>
          <div class="flex-grow-1"></div><button class="btn btn-sm btn-outline-danger" id="udRollbackBtn" onclick="KS.updateRollback()"><i class="bi bi-arrow-counterclockwise me-1"></i>回滚上一版本</button></div>
          <div class="table-responsive" style="max-height:260px;overflow:auto"><table class="table"><thead><tr><th>类型</th><th>时间</th><th>版本</th><th>内容</th><th class="text-end">大小</th></tr></thead><tbody id="udBackupTbody"></tbody></table></div>
          <div class="card-b small text-muted" style="padding-top:0">回滚将用最近一次文件备份还原代码，并按备份时的版本号回退；若更新包含 <code>downgrade.sql</code> 会同步回退表结构。备份最多保留最近 5 份。</div></div>
      </div>
      <div class="col-12 col-lg-5">
        <div class="card"><div class="card-h"><i class="bi bi-shield-check text-primary"></i><span class="tt">更新说明与安全</span></div>
        <div class="card-b small" style="line-height:1.8">
          <div>· 仅管理员可触发；更新包需提供 <code>sha256</code> 强校验。</div>
          <div>· 覆盖前自动备份被改动文件 + 整库 SQL。</div>
          <div>· <code>upgrade.sql</code> 在事务中执行，失败自动回滚数据库。</div>
          <div>· 包内含 <code>downgrade.sql</code> 时，失败会尝试按备份还原并回滚。</div>
          <div>· 更新源地址请填入受你控制的 HTTPS 静态地址。</div>
        </div></div>
        <div class="card mt-3 border-danger"><div class="card-h bg-danger bg-opacity-10"><i class="bi bi-arrow-counterclockwise text-danger"></i><span class="tt text-danger">危险操作</span></div>
        <div class="card-b small">
          <div class="mb-2">一键重置为系统示例数据（保留 1 门示范课程）。<b>不可撤销</b>。</div>
          <button class="btn btn-outline-danger" onclick="App.resetDemoData()"><i class="bi bi-arrow-counterclockwise me-1"></i>一键重置示例数据</button>
        </div></div>
      </div>
    </div>`;
    updateLoadStatus(true);
  };
  function fmtSize(b){ b=Number(b)||0; if(b>=1048576)return (b/1048576).toFixed(1)+' MB'; if(b>=1024)return (b/1024).toFixed(1)+' KB'; return b+' B'; }
  // v1.1.2：高级选项折叠开关
  App.updateToggleAdv=function(){
    const box=$('#udAdv'), lab=$('#udAdvLabel'); if(!box) return;
    const hidden=box.classList.contains('d-none');
    box.classList.toggle('d-none', !hidden);
    if(lab) lab.textContent = hidden ? '收起高级选项' : '查看更新日志与高级选项';
  };
  // v1.1.2：根据后端给的 update_available 渲染「有新版本 / 已最新」两种形态
  function udRenderVersionBox(d){
    const nb=$('#udNewBox'), ok=$('#udOkBox'), nv=$('#udNewVer');
    if(!nb||!ok) return;
    const latest=d.latest_version||'';
    if(d.update_available && latest){
      if(nv) nv.textContent='v'+latest;
      nb.classList.remove('d-none'); ok.classList.add('d-none');
    }else if(latest){
      nb.classList.add('d-none'); ok.classList.remove('d-none');
    }else{
      // 还没检查出结果（或检查失败）：两个都不显示，靠 udLastErr 提示
      nb.classList.add('d-none'); ok.classList.add('d-none');
    }
  }
  // auto=true 时让后端顺便做一次静默检查（进页面即可看到新版本提示）
  async function updateLoadStatus(auto){
    const res=await GET('/api/admin/updateStatus'+(auto?'?auto=1':''));
    if(res.code!==0){ if($('#udCurrent')) $('#udCurrent').textContent='—'; return; }
    const d=res.data||{};
    if($('#udCurrent')) $('#udCurrent').textContent=d.current_version?('v'+d.current_version):'—';
    if($('#udManifest')) $('#udManifest').value=d.manifest_url_custom||'';
    if($('#udMaintBtn')) $('#udMaintBtn').textContent=d.maintenance?'维护中(关闭)':'进入维护模式';
    if($('#udLatest')) $('#udLatest').textContent=d.latest_version?('v'+d.latest_version):'—';
    if($('#udBaseline') && d.app_version_baseline) $('#udBaseline').textContent=d.app_version_baseline;
    if($('#udPhpVer') && d.php_version) $('#udPhpVer').textContent=d.php_version;
    if($('#udLastCheck') && d.last_check_at) $('#udLastCheck').textContent='上次检查：'+d.last_check_at;
    udRenderVersionBox(d);
    // v1.0.3：失败原因在版本号卡片下方红字小字展示，避免「最新版本 —」让人摸不着头脑
    const udErr=$('#udLastErr');
    if(udErr){
      if(d.last_check_error){
        udErr.textContent='⚠ 上次检查未通过：'+d.last_check_error;
        udErr.classList.remove('d-none');
      }else{
        udErr.textContent=''; udErr.classList.add('d-none');
      }
    }
    if($('#udSourceHint')){
      // 实际生效的源 = 看 manifest_url 长啥样，比对 defaultGithub/defaultCnb 来判断
      const GH='https://api.github.com/repos/leeabc-gif/Class-Hours-Tracker-/releases/latest';
      const CNB='https://cnb.cool/bmayan/class-hours-tracker/-/releases/latest/download/manifest.json';
      const mu=d.manifest_url||'';
      let effLabel='';
      if (mu === GH) effLabel='GitHub Releases（默认）';
      else if (mu === CNB) effLabel='CNB 官方 Release（默认）';
      else if (mu === d.manifest_url_custom && d.manifest_url_custom) effLabel='自定义（manifest_url 覆写）';
      else if (mu) effLabel='自定义（manifest_url 直填）';
      else effLabel='未配置';
      $('#udSourceHint').textContent='更新源：'+effLabel+'　·　清单：'+ (mu||'（未配置）');
    }
    const rb=$('#udRollbackBtn'); if(rb) rb.disabled=!d.can_rollback;
    const tb=$('#udBackupTbody');
    if(tb){
      const list=d.backups||[];
      tb.innerHTML=list.length?list.map(b=>'<tr><td>'+(b.type==='db'?'<span class="badge bg-info">数据库</span>':'<span class="badge bg-secondary">文件</span>')+'</td>'
        +'<td class="small">'+esc(b.time||'')+'</td>'
        +'<td>'+(b.type==='files'&&b.version?'v'+esc(b.version):'—')+'</td>'
        +'<td class="small">'+b.files+' 个文件</td>'
        +'<td class="text-end small">'+fmtSize(b.size)+'</td></tr>').join('')
        :'<tr><td colspan="5" class="text-center text-muted">暂无备份，首次升级时会自动创建</td></tr>';
    }
    setUdBusy(false);
  }
  function setUdBusy(busy){
    ['udManifest'].forEach(id=>{ const el=$('#'+id); if(el) el.disabled=busy; });
    const defs={updateRefresh:'读取状态',updateCheck:'检查更新',updateInstall:'更新'};
    // v1.1.2：同名动作现在有多个按钮（主区「立即更新」+ 高级区「一键升级」），
    // 必须用 querySelectorAll 全量处理，否则只有第一个进入加载态、其余仍可重复点击。
    Object.keys(defs).forEach(k=>{
      document.querySelectorAll('[onclick="KS.'+k+'()"]').forEach(el=>{
        if (el.dataset.oh === undefined) el.dataset.oh = el.innerHTML; // 记住原始文案，结束时还原
        if (busy) { el.disabled = true; el.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>' + defs[k] + '中…'; }
        else { el.disabled = false; el.innerHTML = el.dataset.oh; }
      });
    });
  }
  function udOut(html,cls){
    const box=$('#udResult'); if(!box)return;
    box.className='mt-3 alert '+(cls||'alert-info');
    box.innerHTML=html;
  }
  App.updateRefresh=async function(){ setUdBusy(true); await updateLoadStatus(); };
  App.updateCheck=async function(){
    const m=($('#udManifest')? $('#udManifest').value||'' : '').trim();
    // v1.1.2：留空即使用系统默认源，不再强要求填写
    setUdBusy(true); udOut('正在检查更新…');
    const res=await POST('/api/admin/updateCheck',{manifest_url:m});
    setUdBusy(false);
    if(res.code!==0){ udOut('<i class="bi bi-x-circle me-1"></i>'+esc(res.msg),'alert-danger'); return; }
    const d=res.data||{};
    if($('#udLatest')) $('#udLatest').textContent=d.latest_version?('v'+d.latest_version):'—';
    const can=d.update_available;
    udOut(
      (can?'<i class="bi bi-arrow-up-circle me-1"></i><b>发现新版本 v'+esc(d.latest_version)+'</b>（当前 v'+esc(d.current_version)+'）'
        :'<i class="bi bi-check-circle me-1"></i>当前已是最新版本 v'+esc(d.current_version||'')+'')+
      (d.changelog?'<div class="small mt-2"><b>更新说明：</b>'+esc(d.changelog)+'</div>':'')+
      (d.min_php?'<div class="small mt-1 text-muted">要求 PHP &ge; '+esc(d.min_php)+'</div>':'')
      , can?'alert-success':'alert-secondary');
    if(can){ $('#udResult').style.display=''; }
    if($('#udLastCheck')) $('#udLastCheck').textContent='上次检查：'+esc(d.checked_at||new Date().toLocaleString());
    // 同步刷新版本卡片（新增可用态）
    udRenderVersionBox(d);
  };
  App.updateInstall=async function(){
    const m=($('#udManifest')? $('#udManifest').value||'' : '').trim();
    if(!confirm('确定执行在线升级？将备份文件与数据库并进入短暂维护，升级过程请勿关闭页面。')) return;
    setUdBusy(true); udOut('<i class="bi bi-hourglass-split me-1"></i>正在下载并校验更新包…','alert-warning');
    const res=await POST('/api/admin/updateInstall',{manifest_url:m});
    setUdBusy(false);
    if(res.code!==0){ udOut('<i class="bi bi-x-octagon me-1"></i>升级失败：'+esc(res.msg),'alert-danger'); updateLoadStatus(); return; }
    const d=res.data||{};
    udOut('<i class="bi bi-check-circle me-1"></i><b>升级成功</b> '+esc(d.from||'')+' → '+esc(d.to||'')
      +'<div class="small mt-1">备份文件 '+((d.backup&&d.backup.files)||0)+' 个；数据库备份 '+((d.db_backup&&d.db_backup.tables)||0)+' 表。</div>'
      +'<div class="small mt-1 text-muted">建议刷新页面确认新版生效。</div>','alert-success');
    if($('#udCurrent')) $('#udCurrent').textContent=d.to?('v'+d.to):'';
    updateLoadStatus();
  };
  App.updateRollback=async function(){
    if(!confirm('确定回滚到上一版本？将用最近一次文件备份还原代码并回退版本号，期间进入短暂维护模式。')) return;
    const rb=$('#udRollbackBtn'); if(rb) rb.disabled=true;
    setUdBusy(true); udOut('<i class="bi bi-hourglass-split me-1"></i>正在回滚…','alert-warning');
    const res=await POST('/api/admin/updateRollback',{});
    setUdBusy(false);
    if(res.code!==0){ udOut('<i class="bi bi-x-octagon me-1"></i>'+esc(res.msg),'alert-danger'); updateLoadStatus(); return; }
    const d=res.data||{};
    udOut('<i class="bi bi-check-circle me-1"></i><b>回滚完成</b> v'+esc(d.from||'')+' → v'+esc(d.to||'')
      +'<div class="small mt-1">还原文件 '+(d.restored_files||0)+' 个。建议刷新页面确认系统状态。</div>','alert-success');
    updateLoadStatus();
  };
  App.maintenanceToggle=async function(){
    const nowMaint = ($('#udMaintBtn').textContent||'').indexOf('关闭')>-1;
    if(!confirm(nowMaint?'确认关闭维护模式？':'确认开启维护模式？开启后普通用户将看到“升级维护中”提示。')) return;
    const res=await POST('/api/admin/maintenanceToggle',{maintenance: nowMaint?0:1});
    if(res.code===0){ toast('已'+(nowMaint?'关闭':'开启')+'维护模式'); updateLoadStatus(); } else toast(res.msg,'err');
  };

  // -------- 操作日志 --------
  ROUTERS['logs']=function(host){
    host.innerHTML=`<div class="card"><div class="card-h"><i class="bi bi-journal-text text-primary"></i><span class="tt">操作日志</span>
      <div class="flex-grow-1"></div>
      <input id="logKw" class="form-control form-control-sm" placeholder="搜索操作摘要" style="width:180px" onkeydown="if(event.key==='Enter')KS.refreshLogs()">
      <button class="btn btn-outline-secondary btn-sm" onclick="KS.refreshLogs()">搜索</button></div>
      <div class="table-responsive"><table class="table"><thead><tr><th>时间</th><th>操作人</th><th>动作</th><th>对象</th><th>摘要</th><th>IP</th></tr></thead><tbody id="logTbody"></tbody></table></div>
      <div class="card-b d-flex justify-content-end"><nav><ul class="pagination pagination-sm mb-0" id="logPage"></ul></nav></div></div>`;
    loadLogs();
  };
  let logPg=1;
  async function loadLogs(){
    const tb=$('#logTbody'); if(!tb)return;
    const kw=$('#logKw')?$('#logKw').value.trim():'';
    const res=await GET('/api/admin/logs',{page:logPg,limit:15,keyword:kw});
    if(res.code!==0)return;
    if (gone('logTbody')) return; // 页面已切走
    const d=res.data; const list=d.list||[];
    tb.innerHTML=list.map(l=>'<tr><td class="small text-muted">'+esc(l.created_at)+'</td><td><b>'+esc(l.user_name)+'</b></td>'
      +'<td><span class="tag tag-gray">'+esc(l.action_txt)+'</span></td><td class="small">'+esc(l.target)+'#'+l.target_id+'</td>'
      +'<td class="small">'+esc(l.summary)+'</td><td class="small text-muted">'+esc(l.ip)+'</td></tr>').join('')
      ||'<tr><td colspan="6"><div class="dk-empty"><i class="bi bi-journal-text"></i>暂无日志</div></td></tr>';
    let ph=''; const maxP=Math.max(1,Math.ceil(d.total/15));
    ph+='<li class="page-item'+(logPg<=1?' disabled':'')+'"><a class="page-link" href="#" onclick="return KS.logPage('+Math.max(1,logPg-1)+')">«</a></li>';
    for(let i=1;i<=maxP;i++) ph+='<li class="page-item'+(i===logPg?' active':'')+'"><a class="page-link" href="#" onclick="return KS.logPage('+i+')">'+i+'</a></li>';
    ph+='<li class="page-item'+(logPg>=maxP?' disabled':'')+'"><a class="page-link" href="#" onclick="return KS.logPage('+Math.min(maxP,logPg+1)+')">»</a></li>';
    $('#logPage').innerHTML=ph;
  }
  App.logPage=function(n){logPg=n;loadLogs();return false;};
  App.refreshLogs=function(){logPg=1;loadLogs();};

  // =====================================================================
  // 16. 通用弹窗（footer 按钮通过事件委托绑定，避免把函数序列化到 HTML）
  // =====================================================================
  let _modalActions = [];   // 当前弹窗 footer 按钮动作队列
  // Bootstrap Modal 实例缓存：反复 openModal/quickOpen 若每次 new 会累积事件监听导致遮罩异常
  function bsModal(el){
    if (!el) return null;
    if (!el.__bs) el.__bs = new bootstrap.Modal(el);
    return el.__bs;
  }
  function openModal(title, body, footerBtns){
    const m = bsModal($('#bsModal'));
    $('#bsTitle').textContent = title;
    $('#bsBody').innerHTML = body;
    _modalActions = [];
    let f = '';
    (footerBtns || []).forEach((b) => {
      if (b.x) {
        f += '<button type="button" class="btn ' + b.c + ' btn-sm" data-bs-dismiss="modal">' + esc(b.t) + '</button>';
      } else {
        // 关键：data-act 必须用 _modalActions 的真实下标，
        // 前面有取消(x)按钮时 footerBtns 下标会错位，导致保存点了没反应
        const actIdx = _modalActions.length;
        _modalActions.push(b.act);
        f += '<button class="btn ' + b.c + ' btn-sm ms-1" data-act="' + actIdx + '">' + esc(b.t) + '</button>';
      }
    });
    f += '<button type="button" class="btn btn-link btn-sm ms-2" data-bs-dismiss="modal">取消</button>';
    $('#bsFoot').innerHTML = f;
    $('#bsFoot').onclick = async function (ev) {
      const btn = ev.target.closest('[data-act]');
      if (!btn) return;
      const fn = _modalActions[parseInt(btn.dataset.act, 10)];
      if (typeof fn === 'function') { try { await fn(); } catch (e) { toast('操作失败：' + e.message, 'err'); } }
    };
    m.show();
  }
  function hideModal(){ try { const m = bsModal($('#bsModal')); if (m) m.hide(); } catch (e) {} }
  App.hideModal = hideModal;
  App.openModal = openModal;

  // =====================================================================
  // 17. 启动
  // =====================================================================
  // bootstrap.Modal 需要，确保 DOM 就绪
  document.addEventListener('DOMContentLoaded', function(){
    // 登录按钮在 index.html 内是 form onsubmit=KS.login
    restoreSession();
  });
  // 若脚本晚于 DOMContentLoaded（如内联加载顺序），立即尝试
  if (document.readyState === 'complete' || document.readyState === 'interactive') {
    // 避免与 DOMContentLoaded 重复触发：用标志
    if (!window.__ksStarted) { window.__ksStarted = true; restoreSession(); }
  }
})();
