const assert = require('node:assert/strict');
const path = require('node:path');
const os = require('node:os');
const { chromium } = require('playwright');
const base = process.env.RULECRAFT_BROWSER_URL || 'http://127.0.0.1:8927';
async function check(page, selector, predicate) {
    await page.waitForFunction(
        ({ selector, predicate }) => {
            const node = document.querySelector(selector);
            return node && Function('node', 'return (' + predicate + ');')(node);
        },
        { selector, predicate },
    );
}
async function openAction(scope) {
    const details = scope.locator('.rc-advanced').first();
    if ((await details.getAttribute('open')) === null) await details.locator('summary').click();
}
async function openMessages(scope) {
    const details = scope.locator('[data-panel="messages"]').first();
    if ((await details.getAttribute('open')) === null) await details.locator('summary').click();
}
async function nameRule(card, name) {
    await card.getByLabel('Rule name', { exact: true }).fill(name);
    assert.equal(await card.locator('.rc-node-title').innerText(), name);
}
async function generatedExpression(scope) {
    const details = scope.locator('.rc-expression-preview').first();
    if ((await details.getAttribute('open')) === null) await details.locator('summary').click();
    return scope.locator('.rc-expression code').innerText();
}
(async () => {
    const browser = await chromium.launch({
        channel: process.env.RULECRAFT_BROWSER_CHANNEL || 'chrome',
        headless: true,
    });
    try {
        const page = await browser.newPage({ viewport: { width: 1440, height: 1100 } });
        const failures = [];
        page.on('pageerror', (error) => failures.push(error.message));
        page.on('dialog', (dialog) => dialog.accept());
        await page.goto(base);
        const root = page.locator('[data-rulecraft-builder]').first();
        await root.getByRole('button', { name: 'Save policy', exact: true }).waitFor();
        const name = 'browser-loan-' + Date.now();
        await root.getByLabel('Group name', { exact: true }).fill(name);
        await root.getByRole('button', { name: '+ Add condition', exact: true }).click();
        let branch = root.locator('.rc-rule').first();
        await nameRule(branch, 'same-branch');
        await branch.getByLabel('Priority', { exact: true }).fill('10');
        await branch.getByLabel('Field', { exact: true }).selectOption('branch');
        await branch.getByLabel('Compare with', { exact: true }).selectOption('field');
        await branch.getByLabel('Compared field', { exact: true }).selectOption('user_branch');
        await openAction(branch);
        await branch.getByLabel('Action name', { exact: true }).fill('unregistered');
        await branch
            .getByLabel('Action parameters (JSON)', { exact: true })
            .fill('{"rate":1.0,"object":{},"list":[]}');
        await root.getByRole('button', { name: '+ Add group', exact: true }).click();
        let child = root.locator('.rc-group .rc-group').first();
        await child.getByLabel('Group name', { exact: true }).fill('alternatives-' + Date.now());
        await child.getByLabel('Match', { exact: true }).selectOption('any');
        await child.getByRole('button', { name: '+ Add condition', exact: true }).click();
        let limit = child.locator('.rc-rule').first();
        await nameRule(limit, 'limit');
        await limit.getByLabel('Operator', { exact: true }).selectOption('<=');
        await limit.getByLabel('Compare with', { exact: true }).selectOption('field');
        await limit.getByLabel('Compared field', { exact: true }).selectOption('limit');
        await child.getByRole('button', { name: '+ Add condition', exact: true }).click();
        let special = child.locator('.rc-rule').nth(1);
        await nameRule(special, 'special');
        await special.getByLabel('Field', { exact: true }).selectOption('special');
        await special.getByLabel('Value', { exact: true }).selectOption('true');
        const context =
            '{"branch":1,"user_branch":1,"amount":300000,"limit":200000,"special":true}';
        await root.getByLabel('Context (JSON object)', { exact: true }).fill(context);
        await root.getByRole('button', { name: 'Preview conditions', exact: true }).click();
        await check(page, '.rc-message', 'node.textContent.includes("Preview completed")');
        assert.match(await root.locator('.rc-results').first().innerText(), /PASS/);
        assert.match(await root.locator('.rc-results').first().innerText(), /FAIL limit/);
        assert.match(await root.locator('.rc-results').first().innerText(), /PASS special/);
        special = child.locator('.rc-rule').nth(1);

        await special.getByLabel('Editor', { exact: true }).selectOption('expression');
        await special.getByLabel('Expression', { exact: true }).waitFor();
        assert.equal(
            await special.getByLabel('Expression', { exact: true }).inputValue(),
            'special == true',
        );
        await root.getByRole('button', { name: 'Save policy', exact: true }).click();
        await check(page, '.rc-message', 'node.textContent.includes("Policy saved")');
        await page
            .locator('.rc-disclosure[open] > summary')
            .evaluateAll((nodes) => nodes.forEach((node) => node.click()));
        await page.screenshot({
            path: path.join(os.tmpdir(), 'rulecraft-builder-custom.png'),
            fullPage: true,
        });
        await page.goto(base + '/?group=' + encodeURIComponent(name));
        await check(page, '.rc-state', 'node.textContent.includes("Ready")');
        branch = root.locator('.rc-rule').first();
        assert.equal(await branch.getByLabel('Priority', { exact: true }).inputValue(), '10');
        await openAction(branch);
        assert.match(
            await branch.getByLabel('Action parameters (JSON)', { exact: true }).inputValue(),
            /1\.0/,
        );
        assert.equal(
            await root
                .locator('.rc-rule')
                .nth(2)
                .getByLabel('Expression', { exact: true })
                .inputValue(),
            'special == true',
        );
        const forged = await page.evaluate(() => {
            const editor = document.querySelector('[data-rulecraft-builder]').rulecraftEditor;
            return {
                url: editor.settings.save,
                data: { group: editor.draft, revision: editor.revision },
            };
        });
        const forgedResponse = await page.request.post(forged.url, {
            headers: {
                Accept: 'application/json',
                'Sec-Fetch-Site': 'cross-site',
                Origin: 'https://untrusted.example',
            },
            data: forged.data,
        });
        assert.equal(
            forgedResponse.status(),
            419,
            'Web middleware rejects an untrusted request without a CSRF token',
        );
        await page.evaluate(async () => {
            const editor = document.querySelector('[data-rulecraft-builder]').rulecraftEditor;
            const draft = JSON.parse(JSON.stringify(editor.draft));
            draft.rules[0].priority += 1;
            await editor.request(editor.settings.save, { group: draft, revision: editor.revision });
        });
        await root.getByRole('button', { name: 'Save policy', exact: true }).click();
        await check(page, '.rc-message', 'node.textContent.includes("Policy changed")');
        assert.equal(
            await root.getByLabel('Group name', { exact: true }).first().inputValue(),
            name,
        );
        await page.goto(base + '/?multiple=1&theme=bootstrap&override=1');
        await root.getByRole('button', { name: 'Save policy', exact: true }).waitFor();
        await page.keyboard.press('Tab');
        assert.equal(
            await page.evaluate(() => getComputedStyle(document.activeElement).outlineWidth),
            '2px',
        );
        assert.equal(await page.locator('[data-rulecraft-builder]').count(), 2);
        assert.equal(await page.locator('script[src*="assets/builder.js"]').count(), 1);
        assert.equal(await page.locator('link[href*="assets/bootstrap.css"]').count(), 1);
        assert.equal(await page.locator('link[href*="builder-test-overrides.css"]').count(), 1);
        assert.equal(
            await page.locator('body').evaluate((node) => getComputedStyle(node).backgroundColor),
            'rgb(245, 245, 244)',
        );
        assert.equal(await root.evaluate((node) => getComputedStyle(node).borderRadius), '14px');
        assert.equal(
            await root
                .getByRole('button', { name: 'Save policy', exact: true })
                .evaluate((node) => getComputedStyle(node).backgroundColor),
            'rgb(37, 99, 235)',
        );
        assert.equal(
            await root
                .getByLabel('Enabled', { exact: true })
                .evaluate((node) => getComputedStyle(node).backgroundColor),
            'rgb(37, 99, 235)',
        );
        await root.getByLabel('Group name', { exact: true }).fill('First editor');
        assert.equal(
            await page
                .locator('[data-rulecraft-builder]')
                .nth(1)
                .getByLabel('Group name', { exact: true })
                .inputValue(),
            '',
        );
        await page
            .locator('.rc-disclosure[open] > summary')
            .evaluateAll((nodes) => nodes.forEach((node) => node.click()));
        await page.screenshot({
            path: path.join(os.tmpdir(), 'rulecraft-builder-bootstrap.png'),
            fullPage: true,
        });
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(base + '/?group=' + encodeURIComponent(name));
        await check(page, '.rc-state', 'node.textContent.includes("Ready")');
        assert.equal(
            await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
            true,
        );
        await page
            .locator('.rc-disclosure[open] > summary')
            .evaluateAll((nodes) => nodes.forEach((node) => node.click()));
        await page.screenshot({
            path: path.join(os.tmpdir(), 'rulecraft-builder-mobile.png'),
            fullPage: true,
        });
        await root
            .getByLabel('Group name', { exact: true })
            .first()
            .fill('<img src=x onerror="window.rulecraftXss=1">');
        await root.getByLabel('Context (JSON object)', { exact: true }).fill(context);
        await root.getByRole('button', { name: 'Preview conditions', exact: true }).click();
        await check(page, '.rc-message', 'node.textContent.includes("Preview completed")');
        assert.equal(await root.locator('img').count(), 0);
        assert.equal(await page.evaluate(() => window.rulecraftXss), undefined);
        await page.goto(base + '/?free=1&group=' + encodeURIComponent(name));
        await check(page, '.rc-state', 'node.textContent.includes("Ready")');
        assert.equal(
            await root
                .locator('.rc-rule')
                .first()
                .getByLabel('Editor', { exact: true })
                .inputValue(),
            'visual',
        );
        assert.equal(
            await root
                .locator('.rc-rule')
                .first()
                .getByLabel('Field', { exact: true })
                .inputValue(),
            'branch',
        );
        assert.equal(
            await root.locator('.rc-rule').nth(1).getByLabel('Field', { exact: true }).inputValue(),
            'amount',
        );
        await page.goto(base + '/?free=1');
        const freeName = 'free-fields-' + Date.now();
        await root.getByLabel('Group name', { exact: true }).fill(freeName);
        await root.getByRole('button', { name: '+ Add condition', exact: true }).click();
        let freeRule = root.locator('.rc-rule').first();
        await nameRule(freeRule, 'free-amount');
        await freeRule.getByLabel('Field', { exact: true }).fill('loan.amount');

        await freeRule.getByLabel('Field type', { exact: true }).selectOption('number');
        await freeRule.getByLabel('Operator', { exact: true }).selectOption('<=');
        await freeRule.getByLabel('Value', { exact: true }).fill('200000');
        await root
            .getByLabel('Context (JSON object)', { exact: true })
            .fill('{"loan":{"amount":100000}}');
        await root.getByRole('button', { name: 'Preview conditions', exact: true }).click();
        await check(page, '.rc-message', 'node.textContent.includes("Preview completed")');
        assert.match(await root.locator('.rc-results').first().innerText(), /PASS free-amount/);
        await root.getByRole('button', { name: 'Save policy', exact: true }).click();
        await check(page, '.rc-message', 'node.textContent.includes("Policy saved")');
        await page.goto(base + '/?free=1&group=' + encodeURIComponent(freeName));
        await check(page, '.rc-state', 'node.textContent.includes("Ready")');
        assert.equal(await root.getByLabel('Field', { exact: true }).inputValue(), 'loan.amount');

        for (const theme of ['custom', 'bootstrap']) {
            await page.goto(base + '/?free=1&theme=' + theme);
            const functionName = 'functions-' + theme + '-' + Date.now();
            await root.getByLabel('Group name', { exact: true }).fill(functionName);
            await root.getByRole('button', { name: '+ Add condition', exact: true }).click();
            const functionRule = root.locator('.rc-rule').first();
            await nameRule(functionRule, 'credit-check');
            const failureMessage = 'The score must be at least 650. <strong>Review</strong>';
            const successMessage = '<em>The score is sufficient.</em>';
            assert.equal(
                await functionRule
                    .getByLabel('Success message (optional)', { exact: true })
                    .isVisible(),
                false,
            );
            assert.equal(
                await functionRule
                    .getByLabel('Failure message (optional)', { exact: true })
                    .isVisible(),
                false,
            );
            assert.equal(
                await functionRule.locator('[data-panel="messages"] summary').innerText(),
                'Messages — Defaults',
            );
            await openMessages(functionRule);
            await functionRule
                .getByLabel('Success message (optional)', { exact: true })
                .fill(successMessage);
            await functionRule
                .getByLabel('Failure message (optional)', { exact: true })
                .fill(failureMessage);
            await functionRule.getByLabel('Source', { exact: true }).selectOption('function');
            assert.equal(
                await functionRule
                    .getByLabel('Success message (optional)', { exact: true })
                    .isVisible(),
                true,
            );
            assert.equal(
                await functionRule.locator('[data-panel="messages"] summary').innerText(),
                'Messages — 2 customized',
            );
            assert.equal(
                await functionRule.locator('[data-panel="action"]').getAttribute('open'),
                null,
            );
            await openAction(functionRule);
            await functionRule.locator('[data-panel="messages"] summary').click();
            await functionRule.getByLabel('Operator', { exact: true }).selectOption('==');
            assert.equal(
                await functionRule
                    .getByLabel('Failure message (optional)', { exact: true })
                    .isVisible(),
                false,
            );
            assert.notEqual(
                await functionRule.locator('[data-panel="action"]').getAttribute('open'),
                null,
            );
            await functionRule.locator('[data-panel="action"] summary').click();
            assert.equal(
                await root.locator('.rc-function-signature').innerText(),
                'credit_score(int applicant_id): int',
            );
            assert.equal(await root.locator('.rc-source-controls .rc-type-info').count(), 0);
            assert.equal(
                await root.getByLabel('Argument: Applicant ID', { exact: true }).count(),
                0,
            );
            await functionRule.getByLabel('Operator', { exact: true }).selectOption('>=');
            await functionRule.getByLabel('Value', { exact: true }).fill('650');
            await root
                .getByLabel('Context (JSON object)', { exact: true })
                .fill('{"applicant_id":1,"scores":{"1":700}}');
            await root.getByRole('button', { name: 'Preview conditions', exact: true }).click();
            await check(page, '.rc-message', 'node.textContent.includes("Preview completed")');
            assert.match(
                await root.locator('.rc-results').first().innerText(),
                /PASS credit-check/,
            );
            assert.equal(
                await root.locator('.rc-group-message').first().innerText(),
                successMessage,
            );
            assert.equal(await root.locator('.rc-results em').count(), 0);
            await root
                .getByLabel('Context (JSON object)', { exact: true })
                .fill('{"applicant_id":1,"scores":{"1":600}}');
            await root.getByRole('button', { name: 'Preview conditions', exact: true }).click();
            await check(page, '.rc-message', 'node.textContent.includes("Preview completed")');
            assert.match(
                await root.locator('.rc-results').first().innerText(),
                /FAIL credit-check/,
            );
            assert.equal(
                await root
                    .locator('.rc-results')
                    .first()
                    .innerText()
                    .then((text) => text.includes(failureMessage)),
                true,
            );
            assert.equal(await root.locator('.rc-results strong').count(), 0);
            assert.equal(
                await root.locator('.rc-group-message').first().innerText(),
                failureMessage,
            );
            await root
                .getByLabel('Context (JSON object)', { exact: true })
                .fill('{"customer_id":1,"scores":{"1":700}}');
            await root.getByRole('button', { name: 'Preview conditions', exact: true }).click();
            await check(page, '.rc-message', 'node.textContent.includes("Preview completed")');
            assert.match(
                await root.locator('.rc-results').first().innerText(),
                /ERROR credit-check/,
            );
            assert.equal(
                (await root.locator('.rc-results').first().innerText()).includes(failureMessage),
                false,
            );
            await root.getByRole('button', { name: 'Save policy', exact: true }).click();
            await check(page, '.rc-message', 'node.textContent.includes("Policy saved")');
            await page.goto(
                base + '/?free=1&theme=' + theme + '&group=' + encodeURIComponent(functionName),
            );
            await check(page, '.rc-state', 'node.textContent.includes("Ready")');
            assert.equal(
                await root.locator('.rc-function-signature').innerText(),
                'credit_score(int applicant_id): int',
            );
            assert.match(await generatedExpression(root), /credit_score\(applicant_id\) >= 650/);
            assert.equal(
                await root.getByLabel('Success message (optional)', { exact: true }).inputValue(),
                successMessage,
            );
            assert.equal(
                await root.getByLabel('Failure message (optional)', { exact: true }).inputValue(),
                failureMessage,
            );

            await root.getByLabel('Function', { exact: true }).selectOption('eligible');
            await root.getByLabel('Value', { exact: true }).selectOption('true');
            await root.getByLabel('Context (JSON object)', { exact: true }).fill('{"score":700}');
            await root.getByRole('button', { name: 'Preview conditions', exact: true }).click();
            await check(page, '.rc-message', 'node.textContent.includes("Preview completed")');
            assert.match(
                await root.locator('.rc-results').first().innerText(),
                /PASS credit-check/,
            );
            await root.getByLabel('Compare with', { exact: true }).selectOption('function');
            assert.deepEqual(
                await root
                    .getByLabel('Compared function', { exact: true })
                    .locator('option')
                    .evaluateAll((nodes) => nodes.map((n) => n.value)),
                ['eligible'],
            );
            await root.getByLabel('Operator', { exact: true }).selectOption('===');
            await root.getByRole('button', { name: 'Save policy', exact: true }).click();
            await check(page, '.rc-message', 'node.textContent.includes("Policy saved")');
            await page.reload();
            await check(page, '.rc-state', 'node.textContent.includes("Ready")');
            assert.deepEqual(await root.locator('.rc-function-signature').allInnerTexts(), [
                'eligible(int score): bool',
                'eligible(int score): bool',
            ]);
            assert.match(
                await generatedExpression(root),
                /eligible\(score\) === eligible\(score\)/,
            );

            await root.getByLabel('Function', { exact: true }).selectOption('credit_score');
            await root.getByLabel('Compare with', { exact: true }).selectOption('function');
            const offered = await root
                .getByLabel('Compared function', { exact: true })
                .locator('option')
                .evaluateAll((nodes) => nodes.map((n) => n.value));
            assert.equal(offered.includes('eligible'), false);
            await root
                .getByLabel('Compared function', { exact: true })
                .selectOption('adjust_score');
            await root
                .getByLabel('Context (JSON object)', { exact: true })
                .fill('{"applicant_id":1,"scores":{"1":700},"score":650,"bonus":50.0}');
            await root.getByRole('button', { name: 'Preview conditions', exact: true }).click();
            await check(page, '.rc-message', 'node.textContent.includes("Preview completed")');
            assert.match(
                await root.locator('.rc-results').first().innerText(),
                /PASS credit-check/,
            );
            await root.getByRole('button', { name: 'Save policy', exact: true }).click();
            await check(page, '.rc-message', 'node.textContent.includes("Policy saved")');
            await page.reload();
            await check(page, '.rc-state', 'node.textContent.includes("Ready")');
            assert.deepEqual(await root.locator('.rc-function-signature').allInnerTexts(), [
                'credit_score(int applicant_id): int',
                'adjust_score(int score, int|float bonus): int|float',
            ]);
            assert.match(
                await generatedExpression(root),
                /credit_score\(applicant_id\) == adjust_score\(score, bonus\)/,
            );
            assert.equal(
                await page.evaluate(
                    () => document.documentElement.scrollWidth <= window.innerWidth,
                ),
                true,
            );
            await page
                .locator('.rc-disclosure[open] > summary')
                .evaluateAll((nodes) => nodes.forEach((node) => node.click()));
            await page.screenshot({
                path: path.join(os.tmpdir(), 'rulecraft-functions-' + theme + '.png'),
                fullPage: true,
            });

            await root.getByLabel('Source', { exact: true }).selectOption('field');
            await root.getByLabel('Field', { exact: true }).fill('loan_amount');
            await root.getByLabel('Field type', { exact: true }).selectOption('number');
            await root.getByLabel('Operator', { exact: true }).selectOption('<=');
            await root.getByLabel('Compare with', { exact: true }).selectOption('function');
            await root
                .getByLabel('Compared function', { exact: true })
                .selectOption('current_limit');
            assert.equal(
                await root.locator('.rc-function-signature').innerText(),
                'current_limit(): int|float',
            );
            await root
                .getByLabel('Context (JSON object)', { exact: true })
                .fill('{"loan_amount":100,"limit":200}');
            await root.getByRole('button', { name: 'Preview conditions', exact: true }).click();
            await check(page, '.rc-message', 'node.textContent.includes("Preview completed")');
            assert.match(
                await root.locator('.rc-results').first().innerText(),
                /PASS credit-check/,
            );
            await openMessages(functionRule);
            await root.getByLabel('Success message (optional)', { exact: true }).fill('');
            await root.getByLabel('Failure message (optional)', { exact: true }).fill('');
            assert.equal(
                await functionRule.locator('[data-panel="messages"] summary').innerText(),
                'Messages — Defaults',
            );
            await root
                .getByLabel('Context (JSON object)', { exact: true })
                .fill('{"loan_amount":300,"limit":200}');
            await root.getByRole('button', { name: 'Preview conditions', exact: true }).click();
            await check(page, '.rc-message', 'node.textContent.includes("Preview completed")');
            assert.match(
                await root.locator('.rc-results').first().innerText(),
                /Rule "credit-check" did not pass\./,
            );
            await root.getByRole('button', { name: 'Save policy', exact: true }).click();
            await check(page, '.rc-message', 'node.textContent.includes("Policy saved")');
            await page.reload();
            await check(page, '.rc-state', 'node.textContent.includes("Ready")');
            assert.equal(
                await root.getByLabel('Failure message (optional)', { exact: true }).inputValue(),
                '',
            );
            assert.equal(
                await root.getByLabel('Success message (optional)', { exact: true }).inputValue(),
                '',
            );
            await root
                .getByLabel('Context (JSON object)', { exact: true })
                .fill('{"loan_amount":100,"limit":200}');
            await root.getByRole('button', { name: 'Preview conditions', exact: true }).click();
            await check(page, '.rc-message', 'node.textContent.includes("Preview completed")');
            assert.equal(await root.locator('.rc-group-message').count(), 0);
            await root.getByLabel('Operator', { exact: true }).selectOption('in');
            assert.equal(
                await root
                    .getByLabel('Compare with', { exact: true })
                    .locator('option')
                    .evaluateAll((nodes) => nodes.some((n) => n.value === 'function')),
                false,
            );
        }
        await page.goto(base);
        await root.getByLabel('Group name', { exact: true }).fill('default-names-' + Date.now());
        await root.getByRole('button', { name: '+ Add condition', exact: true }).click();
        const firstCondition = root.locator('.rc-rule').first();
        assert.equal(
            await firstCondition
                .getByLabel('Rule name', { exact: true })
                .evaluate((node) => node === document.activeElement),
            true,
        );
        assert.equal(
            await firstCondition.getByLabel('Priority', { exact: true }).isVisible(),
            true,
        );
        assert.equal(await firstCondition.getByLabel('Editor', { exact: true }).isVisible(), true);
        assert.equal(
            await firstCondition
                .getByLabel('Failure message (optional)', { exact: true })
                .isVisible(),
            false,
        );
        assert.equal(
            await firstCondition
                .getByLabel('Success message (optional)', { exact: true })
                .isVisible(),
            false,
        );
        assert.equal(
            await firstCondition.getByLabel('Action name', { exact: true }).isVisible(),
            false,
        );
        assert.equal(
            await firstCondition.getByLabel('Rule name', { exact: true }).isVisible(),
            true,
        );
        assert.equal(
            await firstCondition.getByLabel('Rule name', { exact: true }).inputValue(),
            'rule-1',
        );
        assert.equal(
            await firstCondition
                .getByLabel('Operator', { exact: true })
                .locator('option:checked')
                .innerText(),
            'Equals (==)',
        );
        await root.getByRole('button', { name: '+ Add condition', exact: true }).click();
        await root.getByLabel('Context (JSON object)', { exact: true }).fill('{"amount":0.0}');
        await root.getByRole('button', { name: 'Save policy', exact: true }).click();
        await check(page, '.rc-message', 'node.textContent.includes("Policy saved")');
        const names = await root
            .getByLabel('Rule name', { exact: true })
            .evaluateAll((nodes) => nodes.map((node) => node.value));
        assert.deepEqual(names, ['rule-1', 'rule-2']);
        assert.deepEqual(failures, []);
        process.stdout.write(
            'Browser checks passed: nested rules, preview, save/reload, raw expressions, JSON types, CSRF, stale edits, scoped Bootstrap, CSS overrides, independent instances, mobile, escaping, free fields, provider functions, function comparison targets, custom failure messages, success/group messages, and independent message disclosure panels.\n',
        );
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
