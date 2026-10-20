import { expect, test } from '@playwright/test';

const company = 'Synthetic Medical Research Co.';

// UC-016 / ADR-0033: use an isolated MySQL database and a dummy account.
// Seed one baseline FDA draft for Company=company, Submission=K260001;
// research_candidates, holdings and watchlist_items must initially be empty.
// Disable source/catalog HTTP on the local application. This flow consumes
// saved evidence only: it never presses update or follows an external link.
test('UC-016 市場からの調査候補発見・確認はFDAの企業下書きを再入力せず取り込み、監視登録を分ける', async ({ page, context }) => {
    const baseUrl = process.env.E2E_BASE_URL;
    const email = process.env.E2E_EMAIL;
    const password = process.env.E2E_PASSWORD;
    if (!baseUrl || !email || !password) {
        throw new Error('E2E_BASE_URL, E2E_EMAIL, E2E_PASSWORD を隔離テスト環境に設定してください。');
    }
    const localUrl = new URL(baseUrl);
    if (!['localhost', '127.0.0.1', '[::1]'].includes(localUrl.hostname)) {
        throw new Error('E2E_BASE_URL は隔離テストDBを使うローカルサーバーを指定してください。');
    }
    const externalRequests: string[] = [];
    await context.route('**/*', async (route) => {
        const requestUrl = new URL(route.request().url());
        if (['http:', 'https:'].includes(requestUrl.protocol) && requestUrl.origin !== localUrl.origin) {
            externalRequests.push(`${requestUrl.origin}${requestUrl.pathname}`);
            await route.abort('blockedbyclient');
            return;
        }
        await route.continue();
    });

    await page.goto(`${localUrl.origin}/candidate-research`);
    await expect(page).toHaveURL(/\/login$/);
    await page.getByLabel('メールアドレス').fill(email);
    await page.getByLabel('パスワード').fill(password);
    await page.getByRole('button', { name: 'ログイン', exact: true }).click();
    await expect(page).toHaveURL(/\/holdings$/);

    await page.goto(`${localUrl.origin}/candidate-check`);
    await expect(page.getByText('ウォッチリスト 0件 / 未保有 0件', { exact: true })).toBeVisible();
    await page.goto(`${localUrl.origin}/candidate-research`);
    const drafts = page.getByRole('region', { name: 'Webから見つかった企業候補' });
    const row = drafts.getByRole('row').filter({ hasText: company });
    await expect(row).toHaveCount(1);
    await expect(row).toContainText('医療AI');
    await expect(row).toContainText('初回取得・過去資料');
    await expect(row).toContainText('自動抽出・本人未確認');
    await row.getByRole('button', { name: '候補を確認', exact: true }).click();

    await expect(drafts.getByRole('heading', { name: `${company} — 候補の確認`, exact: true })).toBeVisible();
    await expect(drafts.getByText('K260001', { exact: true })).toBeVisible();
    await expect(drafts.getByRole('link', { name: '元資料を開く', exact: true }))
        .toHaveAttribute('href', /https:\/\/www\.accessdata\.fda\.gov\/.*K260001/i);
    const confirmation = drafts.getByRole('checkbox', { name: '企業名・製品・資料からの要点を、元資料と照合しました。', exact: true });
    const importButton = drafts.getByRole('button', { name: '確認して調査記録へ', exact: true });
    await expect(confirmation).not.toBeChecked();
    await expect(importButton).toBeDisabled();
    await expect(drafts.getByRole('textbox', { name: '本人の追記（任意）', exact: true })).toHaveValue('');
    await expect(drafts.getByRole('button', { name: '監視に追加（調査記録で確認）', exact: true })).toBeDisabled();

    // No company, source, theme or generated explanation is typed again.
    await confirmation.check();
    await expect(importButton).toBeEnabled();
    await importButton.click();
    await expect(page.getByRole('status')).toContainText('調査記録へ取り込みました');
    await expect(page.getByRole('button', { name: company, exact: true })).toHaveCount(1);
    await expect(page.getByText(/^資料からの要点:\s*\S/)).toBeVisible();
    await expect(page.getByText('本人の追記: なし', { exact: true })).toBeVisible();
    await expect(page.getByText('上場主体の確認: 未確認', { exact: true })).toBeVisible();
    await expect(page.getByText('状態: 調査中', { exact: true })).toBeVisible();
    await expect(page.getByText('状態: 監視登録済み', { exact: true })).toHaveCount(0);

    await page.goto(`${localUrl.origin}/candidate-check`);
    await expect(page.getByText('ウォッチリスト 0件 / 未保有 0件', { exact: true })).toBeVisible();
    expect(externalRequests, 'ブラウザから外部へのHTTP要求が発生しないこと').toEqual([]);
});
