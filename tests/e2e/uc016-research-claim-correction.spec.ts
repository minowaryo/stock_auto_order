import { expect, test } from '@playwright/test';

const baseUrl = process.env.E2E_BASE_URL;
const email = process.env.E2E_EMAIL;
const password = process.env.E2E_PASSWORD;

test('UC-016 市場からの調査候補発見・確認は2件目の主張を画面で訂正し履歴を表示する', async ({ page }) => {
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

    const suffix = Date.now();
    const company = `E2E訂正確認企業${suffix}`;
    const firstClaim = `一次資料の主張${suffix}`;
    const secondClaim = `後続資料の主張${suffix}`;
    const correctedClaim = `後続資料を再確認した主張${suffix}`;
    await page.getByRole('combobox', { name: 'テーマ 必須' }).fill('フィジカルAI');
    await page.getByRole('textbox', { name: /^言及された法人/ }).fill(company);
    await page.getByRole('textbox', { name: /^テーマとの関係/ }).fill('供給関係を調べる');
    await page.getByRole('textbox', { name: /^発見経路URL/ }).fill(`https://example.org/e2e/discovery/${suffix}`);
    await page.getByRole('textbox', { name: /^資料名/ }).fill('公開資料');
    await page.getByRole('textbox', { name: /^発行者/ }).fill('紹介元');
    await page.getByRole('textbox', { name: /^自分の要約/ }).fill('元資料と主張を確認する');
    await page.getByRole('button', { name: '調査候補を記録' }).click();
    await expect(page.getByRole('status')).toContainText('調査候補を記録しました');

    await page.getByRole('button', { name: '訂正', exact: true }).first().click();
    const claimForm = page.getByRole('heading', { name: '主張と根拠を追加' }).locator('..');
    await claimForm.getByRole('textbox', { name: /^主張/ }).fill(firstClaim);
    await claimForm.getByRole('textbox', { name: /^根拠URL/ }).fill(`https://example.org/e2e/claims/first/${suffix}`);
    await claimForm.getByRole('button', { name: '主張を追加' }).click();
    await expect(page.getByRole('status')).toContainText('主張と根拠を新しい版に追記しました');
    await expect(claimForm.getByRole('button', { name: /^主張 \d+ を訂正$/ })).toHaveCount(1);

    await claimForm.getByRole('textbox', { name: /^主張/ }).fill(secondClaim);
    await claimForm.getByRole('textbox', { name: /^根拠URL/ }).fill(`https://example.org/e2e/claims/second/${suffix}`);
    await claimForm.getByRole('button', { name: '主張を追加' }).click();
    await expect(claimForm.getByRole('button', { name: /^主張 \d+ を訂正$/ })).toHaveCount(2);

    await claimForm.getByRole('button').filter({ hasText: secondClaim }).click();
    const editClaimForm = page.getByRole('heading', { name: '選択した主張を訂正' }).locator('..');
    await expect(editClaimForm).toBeVisible();
    await editClaimForm.getByRole('textbox', { name: /^主張/ }).fill(correctedClaim);
    await editClaimForm.getByRole('button', { name: '主張の訂正を保存' }).click();
    await expect(page.getByRole('status')).toContainText('主張の訂正を新しい版として保存しました');

    await page.getByRole('button', { name: company }).click();
    await expect(page.getByText(firstClaim, { exact: false }).first()).toBeVisible();
    const claimRevision = page.getByText(/主張と根拠: 変更前/).last();
    await expect(claimRevision).toContainText(secondClaim);
    await expect(claimRevision).toContainText(correctedClaim);
});
