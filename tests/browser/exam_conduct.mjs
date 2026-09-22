// Browser test of conducting an exam (exam phase, step 17) in headless Chrome, through the screens
// a person really uses: a centre and its rooms, importing a candidate roster, allocating seats, and
// checking candidates in for their PIN.
//
//   tests/browser/on_test_database.sh tests/browser/exam_conduct.mjs
//   SHOTS=/some/folder tests/browser/on_test_database.sh tests/browser/exam_conduct.mjs
//
// Run it through on_test_database.sh: it writes an examination and candidates that are not deleted
// by design, so it belongs in a throwaway database. In kmu-cms it uses one Super Admin test staff
// member and one test course with a topic, both cleaned up at the end.
import { execSync, spawn } from 'node:child_process';
import { createHmac, randomBytes } from 'node:crypto';
import { mkdirSync, mkdtempSync, writeFileSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const ASSESS = (process.argv[2] ?? 'https://kmu-assess.test').replace(
    /\/$/,
    '',
);
const MYSQL_CMS = process.env.MYSQL_CMS ?? 'mysql -uroot kmu-cms';
const MYSQL_ASSESS = process.env.MYSQL_ASSESS;
const CHROME =
    process.env.CHROME ??
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const SHOTS = process.env.SHOTS ?? null;
const COURSE_CODE = 'E2E-COND-101';
const SUPER_ADMIN_ROLE = 7;
const PERSON = {
    email: 'qbank-review-conduct-e2e@kmu.local',
    employee: 'TMP-RV-C',
};

if (!MYSQL_ASSESS || /\bkmu_assess$/.test(MYSQL_ASSESS)) {
    console.error(
        'This test writes candidates, which are never deleted. Run it through tests/browser/on_test_database.sh.',
    );
    process.exit(2);
}

const run = (mysql, query) => {
    try {
        return execSync(`${mysql} -N`, {
            encoding: 'utf8',
            input: query,
            stdio: ['pipe', 'pipe', 'pipe'],
        }).trim();
    } catch (error) {
        return `SQL ERROR: ${String(error.stderr).trim()}`;
    }
};
const cms = (q) => run(MYSQL_CMS, q);
const assess = (q) => run(MYSQL_ASSESS, q);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const results = [];
const check = (name, ok, detail = '') =>
    results.push({ name, ok: Boolean(ok), detail });

const env = readFileSync(new URL('../../.env', import.meta.url), 'utf8');
const secret = Buffer.from(
    (env.match(/^KMU_CMS_SSO_SECRET=(.*)$/m) ?? [])[1]
        ?.trim()
        .replace(/^"|"$/g, '') ?? '',
    'base64',
);
const b64url = (buf) =>
    Buffer.from(buf)
        .toString('base64')
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '');
function ticket(staffId, redirect) {
    const now = Math.floor(Date.now() / 1000);
    const payload = b64url(
        JSON.stringify({
            iss: 'kmu-cms',
            aud: 'kmu-assess',
            sub: Number(staffId),
            iat: now,
            exp: now + 60,
            jti: randomBytes(32).toString('hex'),
            redirect,
            branch: 1,
        }),
    );
    return `${payload}.${b64url(createHmac('sha256', secret).update(payload).digest())}`;
}

// ---- temporary CMS data -------------------------------------------------------------------------
function cleanup() {
    const courseId = cms(
        `SELECT id FROM acad_courses WHERE course_code = '${COURSE_CODE}'`,
    );
    if (courseId && !courseId.startsWith('SQL')) {
        cms(`DELETE FROM acad_curriculum_nodes WHERE course_id = ${courseId};
            UPDATE acad_courses SET status = 'retired' WHERE course_code = '${COURSE_CODE}'`);
    }
    cms(`UPDATE staff SET is_active = 0 WHERE email = '${PERSON.email}'`);
}
cleanup();

function staff(person) {
    if (
        cms(`SELECT COUNT(*) FROM staff WHERE email = '${person.email}'`) ===
        '0'
    ) {
        const template = cms(
            `SELECT MIN(staff_id) FROM staff_roles WHERE role_id = ${SUPER_ADMIN_ROLE}`,
        );
        const cols = cms(
            "SELECT GROUP_CONCAT(CONCAT('`', column_name, '`') ORDER BY ordinal_position) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'staff' AND column_name NOT IN ('id','email','employee_id','name','surname','branch_id')",
        );
        cms(`SET SESSION sql_mode = '';
            INSERT INTO staff (${cols}, email, employee_id, name, surname, branch_id) SELECT ${cols}, '${person.email}', '${person.employee}', 'Tmp', '${person.employee}', 1 FROM staff WHERE id = ${template};
            INSERT INTO staff_roles (role_id, staff_id, is_active) VALUES (${SUPER_ADMIN_ROLE}, LAST_INSERT_ID(), 1);`);
    } else {
        cms(`UPDATE staff SET is_active = 1 WHERE email = '${person.email}'`);
    }

    return cms(`SELECT id FROM staff WHERE email = '${person.email}'`);
}

const officer = staff(PERSON);

const professional = cms(
    'SELECT id FROM acad_professionals WHERE class_id = 1 ORDER BY sequence LIMIT 1',
);
cms(`SET @kmu_staff_id = ${officer};
    INSERT INTO acad_courses (course_code, title, class_id, professional_id, course_kind, status)
    VALUES ('${COURSE_CODE}', 'Conduct end-to-end course', 1, ${professional}, 'module', 'active')
    ON DUPLICATE KEY UPDATE status = 'active';`);
const courseId = cms(
    `SELECT id FROM acad_courses WHERE course_code = '${COURSE_CODE}'`,
);
const annual = cms("SELECT id FROM acad_exam_types WHERE code = 'annual'");
const stamp = randomBytes(3).toString('hex');

// The examination is seeded after the officer's first sign-in (below), once their kmu-assess users
// row exists to be its created_by.

// ---- a CSV file of candidates, on disk for the browser to upload ---------------------------------
const csvDir = mkdtempSync(join(tmpdir(), 'conduct-e2e-'));
const csvPath = join(csvDir, 'candidates.csv');
writeFileSync(
    csvPath,
    [
        'candidate_no,name,roll_no',
        `C-${stamp}-01,Ayesha Khan,R-1`,
        `C-${stamp}-02,Bilal Ahmed,R-2`,
        `C-${stamp}-03,Chaudhry Farhan,R-3`,
        ',Missing Number,R-4',
    ].join('\n'),
);

// ---- browser -------------------------------------------------------------------------------------
const chrome = spawn(
    CHROME,
    [
        '--headless=new',
        '--remote-debugging-port=9360',
        `--user-data-dir=${mkdtempSync(join(tmpdir(), 'conduct-e2e-chrome-'))}`,
        '--ignore-certificate-errors',
        '--no-first-run',
        '--window-size=1500,1400',
        'about:blank',
    ],
    { stdio: 'ignore' },
);
let target;
for (let i = 0; i < 50 && !target; i++) {
    await sleep(200);
    try {
        target = (
            await (await fetch('http://127.0.0.1:9360/json/list')).json()
        ).find((t) => t.type === 'page');
    } catch {}
}
const ws = new WebSocket(target.webSocketDebuggerUrl);
await new Promise((r) => ws.addEventListener('open', r));
let nextId = 0;
const pending = new Map();
const jsErrors = [];
ws.addEventListener('message', (e) => {
    const m = JSON.parse(e.data);
    if (m.id && pending.has(m.id)) {
        pending.get(m.id)(m);
        pending.delete(m.id);
    }
    if (m.method === 'Runtime.exceptionThrown')
        jsErrors.push(
            m.params.exceptionDetails?.exception?.description ??
                m.params.exceptionDetails?.text,
        );
    if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error')
        jsErrors.push(
            m.params.args.map((a) => a.value ?? a.description).join(' '),
        );
});
const send = (method, params = {}) =>
    new Promise((r) => {
        const id = ++nextId;
        pending.set(id, r);
        ws.send(JSON.stringify({ id, method, params }));
    });
const evaluate = async (expression) =>
    (
        await send('Runtime.evaluate', {
            expression,
            awaitPromise: true,
            returnByValue: true,
        })
    ).result?.result?.value;
const waitFor = async (expression, tries = 80) => {
    for (let i = 0; i < tries; i++) {
        if (await evaluate(expression)) return true;
        await sleep(250);
    }
    return false;
};
const path = async () => evaluate('location.pathname');
const text = async () =>
    (await evaluate('document.body ? document.body.innerText : ""')) ?? '';
const flat = async () => (await text()).replace(/\s+/g, ' ');
const has = async (selector) =>
    evaluate(`!!document.querySelector(${JSON.stringify(selector)})`);
const click = async (selector) =>
    evaluate(
        `(() => { const el = document.querySelector(${JSON.stringify(selector)}); if (!el) return false; el.click(); return true; })()`,
    );
const setValue = async (selector, value) => {
    await evaluate(
        `(() => { const el = document.querySelector(${JSON.stringify(selector)}); el.focus(); const set = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(el), 'value').set; set.call(el, ${JSON.stringify(String(value))}); el.dispatchEvent(new Event('input', { bubbles: true })); return true; })()`,
    );
    await sleep(150);
};
const setSelect = async (selector, value) =>
    evaluate(
        `(() => { const el = document.querySelector(${JSON.stringify(selector)}); if (!el) return false; el.value = ${JSON.stringify(String(value))}; el.dispatchEvent(new Event('change', { bubbles: true })); return el.value === ${JSON.stringify(String(value))}; })()`,
    );
// A real file is handed to the file input the way Chrome does it, then the change is announced.
const attach = async (selector, file) => {
    const { result: doc } = (await send('DOM.getDocument')).result ?? {};
    const node = (
        await send('DOM.querySelector', {
            nodeId: doc?.root?.nodeId ?? 1,
            selector,
        })
    ).result;
    if (!node?.nodeId) return false;
    await send('DOM.setFileInputFiles', { nodeId: node.nodeId, files: [file] });
    await evaluate(
        `(() => { const el = document.querySelector(${JSON.stringify(selector)}); el.dispatchEvent(new Event('change', { bubbles: true })); return el.files.length; })()`,
    );
    return true;
};
const shot = async (name) => {
    if (SHOTS === null) return;
    mkdirSync(SHOTS, { recursive: true });
    await sleep(300);
    const { result } = await send('Page.captureScreenshot', {
        format: 'png',
        captureBeyondViewport: true,
    });
    writeFileSync(
        join(SHOTS, `${name}.png`),
        Buffer.from(result.data, 'base64'),
    );
};
const signIn = async (staffId, redirect) => {
    await send('Page.navigate', { url: 'about:blank' });
    await evaluate(
        `(() => { const f = document.createElement('form'); f.method = 'post'; f.action = '${ASSESS}/sso/cms'; const i = document.createElement('input'); i.name = 'ticket'; i.value = ${JSON.stringify(ticket(staffId, redirect))}; f.appendChild(i); document.body.appendChild(f); f.submit(); return true; })()`,
    );
    return (
        (await waitFor(
            `location.pathname === ${JSON.stringify(redirect.split('?')[0])}`,
        )) && (await waitFor('document.body.innerText.length > 100'))
    );
};

try {
    await send('Page.enable');
    await send('Runtime.enable');
    await send('DOM.enable');
    await send('Emulation.setDeviceMetricsOverride', {
        width: 1500,
        height: 1400,
        deviceScaleFactor: 1,
        mobile: false,
    });

    // A first sign-in materialises the officer's kmu-assess users row, which the examination and
    // every candidate/centre row below needs as created_by.
    await signIn(officer, '/dashboard');
    const officerUser = assess(
        `SELECT id FROM users WHERE cms_staff_id = ${officer}`,
    );
    assess(`INSERT INTO exm_examinations (public_ref, branch_id, title, programme_id, professional_id, course_id, exam_type_id, duration_minutes, total_marks, pass_percentage, negative_marking, status, created_by, created_at, updated_at)
        VALUES ('EX-E2E-${stamp}', 1, 'Conduct end-to-end examination', 1, ${professional}, ${courseId}, ${annual}, 60, 100, 50, 0, 'draft', ${officerUser}, NOW(), NOW())`);
    const examId = assess(
        `SELECT id FROM exm_examinations WHERE public_ref = 'EX-E2E-${stamp}'`,
    );

    // ---- the landing page lists the examination, and links into it ------------------------------
    check(
        'opening Conduct Exam lists the examination',
        (await signIn(officer, '/exams/conduct')) &&
            (await has(`[data-exam="${examId}"]`)),
        (await flat()).slice(0, 300),
    );
    await shot('1-conduct-index');

    // ---- a centre and a room -----------------------------------------------------------------------
    await signIn(officer, '/exams/conduct/centres');
    await click('[data-test=new-centre]');
    await waitFor("!!document.querySelector('[data-test=new-centre-form]')");
    await setValue('#c-name', 'Main Hall');
    await setValue('#c-code', `MH-${stamp}`);
    await click('[data-test=save-centre]');
    check(
        'a centre is created',
        await waitFor(
            `document.querySelector('[data-centre]')?.textContent.includes('Main Hall')`,
        ),
        (await flat()).slice(0, 300),
    );
    const centreId = assess(
        `SELECT id FROM cand_centres WHERE code = 'MH-${stamp}'`,
    );

    await click('[data-test=new-room]');
    await waitFor("!!document.querySelector('[data-test=new-room-form]')");
    await setValue(`#r-name-${centreId}`, 'Room 1');
    await setValue(`#r-cap-${centreId}`, '2');
    await click('[data-test=save-room]');
    check(
        'a room is added to the centre, with its capacity',
        await waitFor(
            "document.querySelector('[data-room]')?.textContent.includes('Room 1')",
        ),
        (await flat()).slice(0, 300),
    );
    await shot('2-centre');

    // ---- importing candidates -----------------------------------------------------------------------
    await signIn(officer, `/exams/${examId}/candidates`);
    await attach('#file', csvPath);
    await click('[data-test=upload-candidates]');
    check(
        'the CSV is imported: three candidates in, one bad row skipped',
        (await waitFor(
            "document.querySelector('[data-test=summary]')?.textContent.includes('3')",
        )) && (await has('[data-test=import-errors]')),
        (await flat()).slice(0, 400),
    );
    await shot('3-imported');

    // ---- allocating seats -----------------------------------------------------------------------
    await setSelect('[data-test=allocate-centre]', String(centreId));
    await click('[data-test=allocate-all]');
    const seatedToCapacity = await waitFor(
        "[...document.querySelectorAll('[data-candidate]')].filter((r) => r.textContent.includes('Room 1')).length === 2",
    );
    check(
        "two candidates are seated to the room's capacity, one is left over",
        seatedToCapacity &&
            (await has('[data-candidate]')) &&
            (await evaluate(
                "[...document.querySelectorAll('[data-candidate]')].some((r) => r.textContent.includes('Not seated'))",
            )),
        (await flat()).slice(0, 500),
    );

    // The one left over is seated by hand, into the same room (capacity was only a guide for the draw).
    await waitFor(
        "[...document.querySelectorAll('[data-candidate]')].some((r) => r.textContent.includes('Not seated'))",
    );
    const unseatedRow = await evaluate(
        "[...document.querySelectorAll('[data-candidate]')].find((r) => r.textContent.includes('Not seated'))?.getAttribute('data-candidate')",
    );
    await click(`[data-candidate="${unseatedRow}"] [data-test=edit-seat]`);
    await waitFor(
        `!!document.querySelector('[data-candidate="${unseatedRow}"] [data-test=seat-room]')`,
    );
    const roomOptionValue = await evaluate(
        `[...document.querySelector('[data-candidate="${unseatedRow}"] [data-test=seat-room]').options].find((o) => o.textContent.includes('Room 1'))?.value`,
    );
    await setSelect(
        `[data-candidate="${unseatedRow}"] [data-test=seat-room]`,
        roomOptionValue,
    );
    await click(`[data-candidate="${unseatedRow}"] [data-test=save-seat]`);
    check(
        'the last candidate is seated by hand',
        await waitFor(
            "[...document.querySelectorAll('[data-candidate]')].every((r) => !r.textContent.includes('Not seated'))",
        ),
        (await flat()).slice(0, 500),
    );

    // ---- extra time -----------------------------------------------------------------------------
    const firstCandidateId = await evaluate(
        "document.querySelector('[data-candidate]')?.getAttribute('data-candidate')",
    );
    await click(
        `[data-candidate="${firstCandidateId}"] [data-test=edit-extra-time]`,
    );
    await waitFor(
        `!!document.querySelector('[data-candidate="${firstCandidateId}"] [data-test=extra-minutes]')`,
    );
    await setValue(
        `[data-candidate="${firstCandidateId}"] [data-test=extra-minutes]`,
        '15',
    );
    await setValue(
        `[data-candidate="${firstCandidateId}"] [data-test=extra-reason]`,
        'Registered learning difficulty',
    );
    await click(
        `[data-candidate="${firstCandidateId}"] [data-test=save-extra-time]`,
    );
    check(
        'extra time is granted and shown against the candidate',
        await waitFor(
            `document.querySelector('[data-candidate="${firstCandidateId}"]')?.textContent.includes('15 min')`,
        ),
        (await flat()).slice(0, 400),
    );
    await shot('4-allocated');

    // ---- check-in and the PIN ---------------------------------------------------------------------
    const firstCandidateNo = assess(
        `SELECT candidate_no FROM cand_candidates WHERE id = ${firstCandidateId}`,
    );
    await signIn(officer, `/exams/${examId}/checkin`);
    await setValue('[data-test=checkin-search]', firstCandidateNo);
    await click('[data-test=checkin-find]');
    check(
        'searching by candidate number finds them',
        await waitFor(
            `!!document.querySelector('[data-candidate="${firstCandidateId}"]')`,
        ),
        (await flat()).slice(0, 400),
    );

    await click(`[data-candidate="${firstCandidateId}"] [data-test=check-in]`);
    check(
        'checking in issues a one-time PIN, shown once',
        (await waitFor("!!document.querySelector('[data-test=pin-value]')")) &&
            /^\d{6}$/.test(
                (await evaluate(
                    "document.querySelector('[data-test=pin-value]')?.textContent.trim()",
                )) ?? '',
            ),
        (await flat()).slice(0, 400),
    );
    const firstPin = await evaluate(
        "document.querySelector('[data-test=pin-value]')?.textContent.trim()",
    );

    await click(
        `[data-candidate="${firstCandidateId}"] [data-test=reissue-pin]`,
    );
    await waitFor(
        `document.querySelector('[data-test=pin-value]')?.textContent.trim() !== ${JSON.stringify(firstPin)}`,
    );
    const secondPin = await evaluate(
        "document.querySelector('[data-test=pin-value]')?.textContent.trim()",
    );
    check(
        'reissuing gives a different PIN, and the candidate stays checked in',
        secondPin !== firstPin &&
            (await evaluate(
                `document.querySelector('[data-candidate="${firstCandidateId}"]')?.textContent.includes('Checked in')`,
            )),
        `${firstPin} -> ${secondPin}`,
    );
    await shot('5-checked-in');

    check(
        'the PIN is stored only as its hash, never in the clear',
        assess(
            `SELECT pin_hash FROM cand_candidates WHERE id = ${firstCandidateId}`,
        ) !== firstPin &&
            assess(
                `SELECT pin_hash FROM cand_candidates WHERE id = ${firstCandidateId}`,
            ).length > 20,
    );

    check(
        "the database refuses to change a checked-in candidate's number",
        assess(
            `UPDATE cand_candidates SET candidate_no = 'X-HACKED' WHERE id = ${firstCandidateId}`,
        ).includes('cannot be changed'),
    );

    check(
        'every step is in the audit log',
        [
            'candidate.imported',
            'candidate.allocated',
            'candidate.checked_in',
            'candidate.pin_reissued',
            'candidate.extra_time_granted',
            'centre.created',
            'room.created',
        ].every(
            (a) =>
                assess(
                    `SELECT COUNT(*) FROM sec_audit_logs WHERE action = '${a}'`,
                ) !== '0',
        ),
        assess(
            `SELECT GROUP_CONCAT(DISTINCT action) FROM sec_audit_logs WHERE action LIKE 'candidate.%' OR action LIKE 'centre.%' OR action LIKE 'room.%'`,
        ),
    );

    check(
        'no JavaScript errors in the conduct screens',
        jsErrors.length === 0,
        jsErrors.join(' | '),
    );
} catch (error) {
    check(
        `the test run stopped unexpectedly at ${await path()}`,
        false,
        error.stack,
    );
} finally {
    ws.close();
    chrome.kill();
    cleanup();
}

check(
    'the test course is retired and the test person deactivated',
    cms(
        `SELECT status FROM acad_courses WHERE course_code = '${COURSE_CODE}'`,
    ) === 'retired' &&
        cms(`SELECT is_active FROM staff WHERE email = '${PERSON.email}'`) ===
            '0',
);

for (const r of results)
    console.log(
        `${r.ok ? 'PASS' : 'FAIL'}  ${r.name}${r.detail && !r.ok ? `  (${String(r.detail).slice(0, 300)})` : ''}`,
    );
console.log(
    `\npassed: ${results.filter((r) => r.ok).length}, failed: ${results.filter((r) => !r.ok).length}`,
);
process.exit(results.every((r) => r.ok) ? 0 : 1);
