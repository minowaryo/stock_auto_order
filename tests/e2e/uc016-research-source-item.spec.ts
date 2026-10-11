import { expect, test } from '@playwright/test';

const baseUrl = process.env.E2E_BASE_URL;
const email = process.env.E2E_EMAIL;
const password = process.env.E2E_PASSWORD;

// Companion browser check for the approved UC-016 source screen contract.
// An isolated database must contain one baseline item; its local RSS fixture
// returns that item plus "E2E新着資料". External feeds must remain disabled.
test('UC-016 市場からの調査候補発見・確認は情報源の新着を本人が候補に記録する', async ({ page }) => {
    if (!baseUrl || !email || !password) {
        throw new Error('E2E_BASE_URL, E2E_EMAIL, E2E_PASSWORD を隔離テスト環境に設定してください。');
    }
    await page.goto(`${baseUrl}/candidate-research`);
    await expect(page).toHaveURL(/\/login$/);
    await page.getByLabel('メールアドレス').fill(email);
    await page.getByLabel('パスワード').fill(password);
    await page.getByRole('button', { name: 'ログイン' }).click();
    await expect(page).toHaveURL(/\/holdings$/);
    await page.goto(`${baseUrl}/candidate-research`);

    const source = page.locator('section').filter({ has: page.getByRole('heading', { name: 'E2E試験用RSS' }) });
    await expect(source).toContainText('初回取得（基準線）');
    await expect(source).toContainText('未処理の新着: 0');
    await page.getByRole('button', { name: '今すぐ確認' }).click();
    await expect(source).toContainText('新着あり');
    await expect(source).toContainText('未処理の新着: 1');

    const item = page.getByRole('article').filter({ has: page.getByRole('link', { name: 'E2E新着資料', exact: true }) });
    await item.getByRole('button', { name: '対象外', exact: true }).click();
    await expect(item.getByRole('button', { name: '未処理へ戻す' })).toBeVisible();
    await expect(source).toContainText('未処理の新着: 0');
    await item.getByRole('button', { name: '未処理へ戻す' }).click();
    await expect(item.getByRole('button', { name: '候補に記録', exact: true })).toBeVisible();
    await expect(source).toContainText('未処理の新着: 1');
    await item.getByRole('button', { name: '候補に記録', exact: true }).click();
    await expect(page.getByRole('textbox', { name: /^発見経路URL/ })).toHaveValue('https://example.org/source-e2e-new');
    await expect(page.getByRole('textbox', { name: /^資料名/ })).toHaveValue('E2E新着資料');
    await expect(page.getByRole('textbox', { name: /^発行者/ })).toHaveValue('E2E試験用RSS');
    await expect(page.getByLabel('紹介元の投稿日（不明なら空欄）')).toHaveValue('2026-10-09');
    await page.getByRole('combobox', { name: 'テーマ 必須' }).fill('フィジカルAI');
    await page.getByRole('textbox', { name: /^言及された法人/ }).fill('E2E情報源調査企業');
    await page.getByRole('textbox', { name: /^テーマとの関係/ }).fill('関連する供給網を調べる');
    await page.getByRole('textbox', { name: /^自分の要約/ }).fill('紹介資料の入口を記録。一次資料と上場主体は未確認。');
    await page.getByRole('button', { name: '調査候補を記録', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('調査候補を記録しました');
    await expect(item).toContainText('候補に記録済み');
    await expect(source).toContainText('未処理の新着: 0');
    await expect(page.getByRole('button', { name: 'E2E情報源調査企業', exact: true })).toBeVisible();
});
