// Browser test of importing questions from a spreadsheet (Step 11) in headless Chrome.
//
//   node tests/browser/question_import.mjs https://kmu-assess.test
//
// Local development only. Like the editor test it reuses one test staff member (Super Admin) and one
// test course with a discipline and a topic in the CMS database; both are activated at the start and
// deactivated/retired at the end, because courses and staff who wrote questions are never deleted.
// The uploaded file, its checked rows and the drafts it created are removed. Audit entries stay.
import { execSync, spawn } from 'node:child_process';
import { createHmac, randomBytes } from 'node:crypto';
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const ASSESS = (process.argv[2] ?? 'https://kmu-assess.test').replace(
    /\/$/,
    '',
);
const MYSQL_CMS = process.env.MYSQL_CMS ?? 'mysql -uroot kmu-cms';
const MYSQL_ASSESS = process.env.MYSQL_ASSESS ?? 'mysql -uroot kmu_assess';
const CHROME =
    process.env.CHROME ??
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const EMAIL = 'qbank-import-e2e@kmu.local';
const COURSE_CODE = 'E2E-IMP-101';
const SUPER_ADMIN_ROLE = 7;

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

// ---- temporary CMS and module data -----------------------------------------------------------
function cleanup() {
    const staffId = cms(`SELECT id FROM staff WHERE email = '${EMAIL}'`);
    const courseId = cms(
        `SELECT id FROM acad_courses WHERE course_code = '${COURSE_CODE}'`,
    );

    if (courseId && !courseId.startsWith('SQL')) {
        const questionIds = assess(
            `SELECT GROUP_CONCAT(id) FROM qb_questions WHERE course_id = ${courseId}`,
        );
        if (questionIds && questionIds !== 'NULL') {
            // Imported questions are still drafts, so they go; anything reviewed stays, archived.
            assess(`UPDATE qb_questions SET active_version_id = NULL WHERE id IN (${questionIds});
                DELETE FROM qb_question_versions WHERE question_id IN (${questionIds}) AND status = 'draft';
                DELETE FROM qb_questions WHERE id IN (${questionIds}) AND id NOT IN (SELECT question_id FROM qb_question_versions);
                UPDATE qb_questions SET is_archived = 1, archive_reason = 'question import end-to-end test' WHERE id IN (${questionIds})`);
        }
        cms(`DELETE FROM acad_curriculum_nodes WHERE course_id = ${courseId} AND depth = 2;
            DELETE FROM acad_curriculum_nodes WHERE course_id = ${courseId};
            UPDATE acad_courses SET status = 'retired' WHERE id = ${courseId}`);
    }

    if (staffId && !staffId.startsWith('SQL')) {
        const userId = assess(
            `SELECT id FROM users WHERE cms_staff_id = ${staffId}`,
        );
        if (userId) {
            assess(`DELETE FROM qb_imports WHERE uploaded_by = ${userId};
                DELETE FROM sessions WHERE user_id = ${userId}`);
        }
        assess(
            `DELETE FROM sso_consumed_tickets WHERE cms_staff_id = ${staffId}`,
        );
        cms(`UPDATE staff SET is_active = 0 WHERE id = ${staffId}`);
    }
}

cleanup();
// The same test author every run: a staff member who has written questions cannot be deleted.
if (cms(`SELECT COUNT(*) FROM staff WHERE email = '${EMAIL}'`) === '0') {
    const template = cms(
        `SELECT MIN(staff_id) FROM staff_roles WHERE role_id = ${SUPER_ADMIN_ROLE}`,
    );
    const cols = cms(
        "SELECT GROUP_CONCAT(CONCAT('`', column_name, '`') ORDER BY ordinal_position) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'staff' AND column_name NOT IN ('id','email','employee_id','name','surname','branch_id')",
    );
    cms(`SET SESSION sql_mode = '';
        INSERT INTO staff (${cols}, email, employee_id, name, surname, branch_id) SELECT ${cols}, '${EMAIL}', 'TMP-QB-IMPORT', 'Tmp', 'Importer', 1 FROM staff WHERE id = ${template};
        INSERT INTO staff_roles (role_id, staff_id, is_active) VALUES (${SUPER_ADMIN_ROLE}, LAST_INSERT_ID(), 1);`);
} else {
    cms(`UPDATE staff SET is_active = 1 WHERE email = '${EMAIL}'`);
}
const staffId = cms(`SELECT id FROM staff WHERE email = '${EMAIL}'`);
assess(
    `UPDATE users SET cms_staff_id = ${staffId}, is_active = 1 WHERE email = '${EMAIL}'`,
);

const professional = cms(
    'SELECT id FROM acad_professionals WHERE class_id = 1 ORDER BY sequence LIMIT 1',
);
if (
    cms(
        `SELECT COUNT(*) FROM acad_courses WHERE course_code = '${COURSE_CODE}'`,
    ) === '0'
) {
    cms(`SET @kmu_staff_id = ${staffId};
        INSERT INTO acad_courses (course_code, title, class_id, professional_id, course_kind, status)
        VALUES ('${COURSE_CODE}', 'Import end-to-end course', 1, ${professional}, 'module', 'active');`);
} else {
    cms(
        `SET @kmu_staff_id = ${staffId}; UPDATE acad_courses SET status = 'active' WHERE course_code = '${COURSE_CODE}'`,
    );
}
const courseId = cms(
    `SELECT id FROM acad_courses WHERE course_code = '${COURSE_CODE}'`,
);
cms(`INSERT INTO acad_curriculum_nodes (course_id, parent_id, level_type_id, code, name, path, depth, sort_order, is_active)
     VALUES (${courseId}, NULL, 3, 'E2E-ID', 'Cardiology (discipline)', '/', 1, 1, 1);`);
const disciplineNode = cms(
    `SELECT id FROM acad_curriculum_nodes WHERE course_id = ${courseId} AND depth = 1`,
);
cms(`INSERT INTO acad_curriculum_nodes (course_id, parent_id, level_type_id, code, name, path, depth, sort_order, is_active)
     VALUES (${courseId}, ${disciplineNode}, 4, 'E2E-IT', 'Acute coronary syndrome', '/${disciplineNode}/', 2, 1, 1);`);

// ---- the spreadsheet an author would upload ---------------------------------------------------
// Two good rows, one with a type nobody recognises, and one that repeats the first row.
const TOPIC = 'Acute coronary syndrome';
const csv = [
    'Type of question,Course ID,Topic,Question,Lead-in,Marks,Options,Answer key,Items,References,Tags',
    `SBA,${COURSE_CODE},${TOPIC},"A 54-year-old man has crushing chest pain radiating to the jaw for 40 minutes.","Which investigation is most useful first?",1,"ECG | Chest radiograph | Echocardiogram | Coronary angiography",A,,"Harrison, 21st ed p. 1875","ECG"`,
    `MTF,${COURSE_CODE},${TOPIC},"Regarding the management of an inferior myocardial infarction:",,3,,,"Aspirin reduces mortality = true | Nitrates are given in right ventricular infarction = false | Reperfusion within 90 minutes is the aim = true",,`,
    `Guess the answer,${COURSE_CODE},${TOPIC},"A question whose type nobody recognises at all.",,1,"Yes | No",A,,,`,
    `SBA,${COURSE_CODE},${TOPIC},"A 54-year-old man has crushing chest pain radiating to the jaw for 40 minutes.","Which investigation is most useful first?",1,"ECG | Chest radiograph | Echocardiogram | Coronary angiography",A,,,`,
].join('\n');
const uploadDir = mkdtempSync(join(tmpdir(), 'qb-import-e2e-file-'));
const uploadPath = join(uploadDir, 'cardiology-questions.csv');
writeFileSync(uploadPath, `${csv}\n`);

// ---- browser ----------------------------------------------------------------------------------
const chrome = spawn(
    CHROME,
    [
        '--headless=new',
        '--remote-debugging-port=9354',
        `--user-data-dir=${mkdtempSync(join(tmpdir(), 'qb-import-e2e-'))}`,
        '--ignore-certificate-errors',
        '--no-first-run',
        '--window-size=1500,1100',
        'about:blank',
    ],
    { stdio: 'ignore' },
);
let target;
for (let i = 0; i < 50 && !target; i++) {
    await sleep(200);
    try {
        target = (
            await (await fetch('http://127.0.0.1:9354/json/list')).json()
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
const load = async (p) => {
    await send('Page.navigate', { url: ASSESS + p });
    await waitFor("document.readyState === 'complete'");
    await sleep(800);
};
const click = async (selector) =>
    evaluate(
        `(() => { const el = document.querySelector(${JSON.stringify(selector)}); if (!el) return false; el.click(); return true; })()`,
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

try {
    await send('Page.enable');
    await send('Runtime.enable');
    await send('DOM.enable');

    await send('Page.navigate', { url: 'about:blank' });
    await evaluate(
        `(() => { const f = document.createElement('form'); f.method = 'post'; f.action = '${ASSESS}/sso/cms'; const i = document.createElement('input'); i.name = 'ticket'; i.value = ${JSON.stringify(ticket(staffId, '/questions/imports'))}; f.appendChild(i); document.body.appendChild(f); f.submit(); return true; })()`,
    );
    check(
        'the import screen opens from kmu-cms',
        await waitFor(
            "location.pathname === '/questions/imports' && !!document.querySelector('[data-test=import-file]')",
        ),
        await path(),
    );

    await load('/dashboard');
    await evaluate(
        "[...document.querySelectorAll('a')].find(a => a.textContent.trim() === 'Import questions')?.click()",
    );
    check(
        'the sidebar offers importing questions',
        await waitFor("location.pathname === '/questions/imports'"),
        await path(),
    );

    // The template tells the author which columns to fill in.
    const template = await evaluate(
        `fetch('/questions/imports/template', { headers: { Accept: 'text/csv' } }).then(r => r.text())`,
    );
    check(
        'the template names the columns and gives an example of each common type',
        /stem/.test(template ?? '') &&
            /options/.test(template ?? '') &&
            /\bsba\b/.test(template ?? '') &&
            /\bmtf\b/.test(template ?? '') &&
            /numerical/.test(template ?? ''),
        String(template).split('\n')[0]?.slice(0, 200),
    );

    check(
        'the screen explains the columns when asked',
        (await evaluate(
            "[...document.querySelectorAll('button')].find(b => b.textContent.includes('Which columns'))?.click(), true",
        )) &&
            (await waitFor(
                '!!document.querySelector("[data-test=column-help]")',
            )),
    );

    // A file that is not a spreadsheet at all is refused before anything is read.
    const notes = join(uploadDir, 'notes.pdf');
    writeFileSync(notes, '%PDF-1.4 not really a spreadsheet\n');
    await attach('[data-test=import-file]', notes);
    await click('[data-test=check-file]');
    check(
        'a file that is not a spreadsheet is refused',
        await waitFor(
            "location.pathname === '/questions/imports' && /Upload a CSV or Excel file/.test(document.body.innerText)",
            40,
        ),
        (await text()).replace(/\s+/g, ' ').slice(0, 300),
    );

    // The real file: checked row by row, and nothing is in the bank yet.
    const questionsBefore = assess(
        `SELECT COUNT(*) FROM qb_questions WHERE course_id = ${courseId}`,
    );
    await load('/questions/imports');
    await attach('[data-test=import-file]', uploadPath);
    await click('[data-test=check-file]');
    check(
        'the checked file opens its preview',
        await waitFor(
            '/^\\/questions\\/imports\\/\\d+$/.test(location.pathname)',
        ),
        await path(),
    );
    const importId = Number((await path()).split('/').pop());
    const preview = (await text()).replace(/\s+/g, ' ');

    check(
        'the preview counts the rows that are ready and the ones with a problem',
        /4 rows were read and checked/.test(preview) &&
            /Nothing has been added to the question bank yet/.test(preview),
        preview.slice(0, 300),
    );
    check(
        'nothing was written to the question bank by the check',
        assess(
            `SELECT COUNT(*) FROM qb_questions WHERE course_id = ${courseId}`,
        ) === questionsBefore,
        `before=${questionsBefore}`,
    );
    check(
        'the two good rows are ready and the other two are held back',
        assess(
            `SELECT CONCAT(rows_total, '/', rows_valid, '/', rows_invalid) FROM qb_imports WHERE id = ${importId}`,
        ) === '4/2/2',
        assess(
            `SELECT GROUP_CONCAT(CONCAT(row_number, ':', status) ORDER BY row_number) FROM qb_import_rows WHERE import_id = ${importId}`,
        ),
    );
    check(
        'each row says what is wrong with it, by its line in the file',
        await evaluate(
            '!!document.querySelector(\'[data-row-errors="4"]\') && /no question type called/.test(document.querySelector(\'[data-row-errors="4"]\').innerText) && /line 2 of this file/.test(document.querySelector(\'[data-row-errors="5"]\').innerText)',
        ),
        preview.slice(0, 600),
    );
    check(
        'the reader understood the parts of each question',
        /Single best answer/.test(preview) &&
            /4 options \(1 correct\)/.test(preview) &&
            /3 statements/.test(preview) &&
            new RegExp(TOPIC).test(preview),
        preview.slice(0, 600),
    );

    await click('[data-test=only-invalid]');
    await sleep(900);
    check(
        'the preview can show only the rows with a problem',
        (await evaluate(
            "[...document.querySelectorAll('[data-row]')].map(r => r.dataset.row).join(',')",
        )) === '4',
        await evaluate(
            "[...document.querySelectorAll('[data-row]')].map(r => r.dataset.row).join(',')",
        ),
    );
    await load(`/questions/imports/${importId}?only=duplicate`);
    check(
        'the repeated row is listed on its own',
        (await evaluate(
            "[...document.querySelectorAll('[data-row]')].map(r => r.dataset.row).join(',')",
        )) === '5',
        await evaluate(
            "[...document.querySelectorAll('[data-row]')].map(r => r.dataset.row).join(',')",
        ),
    );

    // Committing puts the good rows in as drafts, and only those.
    await load(`/questions/imports/${importId}`);
    check(
        'the button says how many questions will be imported',
        /Import 2 questions/.test(await text()),
        (await text()).replace(/\s+/g, ' ').slice(0, 300),
    );
    await click('[data-test=commit-import]');
    check(
        'the file reports what was imported',
        await waitFor(
            '/2 of 4 rows were imported as drafts/.test(document.body.innerText)',
        ),
        (await text()).replace(/\s+/g, ' ').slice(0, 400),
    );

    const imported = assess(
        `SELECT GROUP_CONCAT(CONCAT(t.code, '=', v.status, ':', v.source) ORDER BY t.code)
         FROM qb_question_versions v
         JOIN qb_question_types t ON t.id = v.question_type_id
         WHERE v.course_id = ${courseId}`,
    );
    check(
        'both questions are in the bank as drafts that remember they came from a file',
        imported ===
            'multiple_true_false=draft:import,single_best_answer=draft:import',
        imported,
    );
    check(
        'each draft is linked to the line of the file it came from',
        assess(
            `SELECT COUNT(*) FROM qb_question_versions v JOIN qb_import_rows r ON r.id = v.import_row_id WHERE r.import_id = ${importId} AND r.version_id = v.id`,
        ) === '2',
        assess(
            `SELECT GROUP_CONCAT(CONCAT(row_number, ':', status) ORDER BY row_number) FROM qb_import_rows WHERE import_id = ${importId}`,
        ),
    );
    check(
        'the answers, statements and reference of the rows were kept',
        assess(
            `SELECT COUNT(*) FROM qb_question_options o JOIN qb_question_versions v ON v.id = o.version_id WHERE v.course_id = ${courseId} AND o.is_correct = 1`,
        ) === '1' &&
            assess(
                `SELECT COUNT(*) FROM qb_question_items i JOIN qb_question_versions v ON v.id = i.version_id WHERE v.course_id = ${courseId} AND i.is_true = 1`,
            ) === '2' &&
            assess(
                `SELECT COUNT(*) FROM qb_references f JOIN qb_question_versions v ON v.id = f.version_id WHERE v.course_id = ${courseId}`,
            ) === '1',
    );

    // The same file again: the questions are already in the bank, so the rows are only warned about.
    await load('/questions/imports');
    await attach('[data-test=import-file]', uploadPath);
    await click('[data-test=check-file]');
    await waitFor('/^\\/questions\\/imports\\/\\d+$/.test(location.pathname)');
    check(
        'a row already in the bank is pointed out but still allowed',
        /already in the bank/.test(await text()),
        (await text()).replace(/\s+/g, ' ').slice(0, 400),
    );
    const secondImport = Number((await path()).split('/').pop());
    await evaluate('window.confirm = () => true');
    await click('[data-test=discard-import]');
    check(
        'a checked file can be discarded without touching the bank',
        (await waitFor("location.pathname === '/questions/imports'")) &&
            assess(
                `SELECT status FROM qb_imports WHERE id = ${secondImport}`,
            ) === 'discarded' &&
            assess(
                `SELECT COUNT(*) FROM qb_question_versions WHERE course_id = ${courseId}`,
            ) === '2',
        await path(),
    );

    check(
        'the imported drafts can be opened in the question bank',
        (await load('/questions?search=crushing chest pain')) === undefined &&
            /Single best answer|draft/i.test(await text()),
        (await text()).replace(/\s+/g, ' ').slice(0, 300),
    );

    check(
        'the check and the commit are both in the audit log',
        assess(
            `SELECT GROUP_CONCAT(DISTINCT action ORDER BY action) FROM sec_audit_logs WHERE entity_type = 'question_import' AND entity_id IN (${importId}, ${secondImport})`,
        ) ===
            'qbank.import.checked,qbank.import.committed,qbank.import.discarded',
        assess(
            `SELECT GROUP_CONCAT(DISTINCT action) FROM sec_audit_logs WHERE entity_type = 'question_import' AND entity_id IN (${importId}, ${secondImport})`,
        ),
    );

    check(
        'no JavaScript errors while importing',
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
    'the test course is retired, the test importer deactivated and the file removed',
    cms(
        `SELECT status FROM acad_courses WHERE course_code = '${COURSE_CODE}'`,
    ) === 'retired' &&
        cms(`SELECT is_active FROM staff WHERE email = '${EMAIL}'`) === '0' &&
        assess(
            `SELECT COUNT(*) FROM qb_imports i JOIN users u ON u.id = i.uploaded_by WHERE u.email = '${EMAIL}'`,
        ) === '0',
);

for (const r of results)
    console.log(
        `${r.ok ? 'PASS' : 'FAIL'}  ${r.name}${r.detail && !r.ok ? `  (${String(r.detail).slice(0, 300)})` : ''}`,
    );
console.log(
    `\npassed: ${results.filter((r) => r.ok).length}, failed: ${results.filter((r) => !r.ok).length}`,
);
process.exit(results.every((r) => r.ok) ? 0 : 1);
