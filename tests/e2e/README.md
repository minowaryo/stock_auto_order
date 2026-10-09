# UC-016 Playwright E2E

`uc016-research-candidate.spec.ts` は、未同定候補を画面で記録し、照合元がないときに監視登録を保留する操作を確認する。監視登録成功の分岐は `tests/Feature/UC016ResearchWatchlistHandoffTest.php` が検証する。

`uc016-research-claim-correction.spec.ts` は、同じ候補に2件の主張を追記し、2件目を選んで訂正した後、変更履歴に訂正前後が表示される操作を確認する。

実行前に、**専用MySQLテストDB**へマイグレーションを適用し、ダミーユーザーを1件用意する。`APP_ENV=testing` と専用の `DB_DATABASE` を設定して、そのDBを参照するLaravelサーバーを起動する。PHP組込みサーバーを直接使う場合は、作業ディレクトリを `public/` にしてLaravelの `vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php` をルーターとして渡す。

ブラウザは `npx playwright install chromium` で導入する。テストには `E2E_BASE_URL`、`E2E_EMAIL`、`E2E_PASSWORD` を渡して、次を実行する。

```bash
npx playwright test tests/e2e/uc016-research-candidate.spec.ts --reporter=line
npx playwright test tests/e2e/uc016-research-claim-correction.spec.ts --reporter=line
```

実運用のDB・アカウントは使わない。テストは新しい候補行を作るため、実行後は専用DBを再利用する前に確認する。
