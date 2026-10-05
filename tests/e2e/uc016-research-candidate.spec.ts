import { expect, test } from '@playwright/test';

const baseUrl = process.env.E2E_BASE_URL;
const email = process.env.E2E_EMAIL;
const password = process.env.E2E_PASSWORD;

test('UC-016 市場からの調査候補発見・確認は未同定の企業を画面で記録し照合未実施を示す', async ({ page }) => {
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
    await expect(page.getByRole('heading', { name: '調査候補', exact: true })).toBeVisible();

    const company = `E2E検証企業${Date.now()}`;
    await page.getByRole('combobox', { name: 'テーマ 必須' }).fill('フィジカルAI');
    await page.getByRole('textbox', { name: /^言及された法人/ }).fill(company);
    await page.getByRole('textbox', { name: /^テーマとの関係/ }).fill('搬送ロボットの供給網を調査する');
    await page.getByRole('textbox', { name: /^発見経路URL/ }).fill('https://example.org/e2e/research');
    await page.getByRole('textbox', { name: /^資料名/ }).fill('調査紹介記事');
    await page.getByRole('textbox', { name: /^発行者/ }).fill('紹介元');
    await page.getByRole('textbox', { name: /^自分の要約/ }).fill('記事を読んだ。元発表は未確認。');
    await page.getByRole('button', { name: '調査候補を記録' }).click();

    await expect(page.getByRole('status')).toContainText('調査候補を記録しました');
    await expect(page.getByRole('button', { name: company })).toBeVisible();
    await expect(page.getByText('同定未了').first()).toBeVisible();
    await page.getByRole('button', { name: '監視に追加' }).first().click();
    await expect(page.getByText('照合未実施').last()).toBeVisible();
});
