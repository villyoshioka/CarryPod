# Carry Pod

**WordPress サイトを静的サイトに変換するプラグイン。**

[![License: GPLv3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![WordPress](https://img.shields.io/badge/WordPress-6.8%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-blue.svg)](https://www.php.net/)
[![Version](https://img.shields.io/badge/Version-3.0.0-green.svg)](https://github.com/villyoshioka/CarryPod/releases)

> **注意**: **このプラグインについて、コードは公開していますが、サポートは行っていません。**

---

## Carry Pod って？

WordPress サイトを、まるごと静的ファイルに変換するプラグインです。  
自分の PC で作ったサイトを、GitHub・Cloudflare Workers・Netlify などへ簡単に公開できます。メンテナンスの手間とセキュリティリスクを抑えつつ、表示も軽くなります。

---

## Carry Pod ができること

- **複数の出力先に対応** — GitHub / GitLab / Cloudflare Workers / Netlify / ローカル Git / ZIP / ローカルディレクトリから選べます。
- **wp-includes / wp-content の変名** — WordPress 特有の狙われやすいフォルダ名を、自由に変更できます。
- **自動静的化** — 投稿の公開や更新をトリガーに、自動で静的化を実行できます。
- **管理画面の自動ロック** — 実行中は投稿編集やテーマ変更などの操作を自動で無効化し、状態のズレを防ぎます。
- **設定のインポート / エクスポート** — 設定を JSON で書き出して、別環境にそのまま持ち込めます。
- **実行前バリデーション** — 設定不備があれば、実行ボタンが押せないように警告を出します。

---

## 想定環境について

Carry Pod は、[Local](https://localwp.com/) や [MAMP](https://www.mamp.info/) などのツールを使って、自分の PC（ローカル）で WordPress を動かすスタイルを想定しています。

- **セキュリティに強い** — 管理画面はあなたの PC からしかアクセスできないので、外部から狙われにくいです。
- **コストを抑えられる** — WordPress 専用サーバーは不要。静的ホスティングなら無料〜低コストで公開できます。
- **壊しても怖くない** — 自分の手元なら、好きなだけ試行錯誤できます。

必要なのは PC とドメイン代だけ。手軽にサイト運営を始められます。

---

## 使いかたの 3 ステップ

1. **プラグインを入れる** — [Releases](https://github.com/villyoshioka/CarryPod/releases) から ZIP をダウンロードし、WordPress 管理画面の「プラグイン → 新規追加 → プラグインのアップロード」からインストールして有効化します。
2. **設定する** — 管理メニューに追加された "CarryPod" を開き、出力先とオプションを選びます。
3. **実行する** — 「静的化を実行」ボタンを押すだけ。あとは待つだけです。

### デバッグモード

トラブル時や動作確認時は、URL の末尾に `&debugmode=on` を付けると詳細な状況が確認できます（解除は `&debugmode=off`）。

---

## ライセンスと使用モジュール

Carry Pod は [GPLv3 ライセンス](https://www.gnu.org/licenses/gpl-3.0) で公開されています。  
バックグラウンド処理には [Action Scheduler](https://actionscheduler.org/) を利用しています。

このプラグインの制作にあたり、先人である [WP2Static](https://github.com/elementor/wp2static) 作者の Leon Stafford 氏に深く敬意を表します。

---

## プライバシーについて

Carry Pod はあなたの手元だけで動きます。あなたのデータを勝手に集めたり、こっそり追跡したりすることは一切ありません。

---

## 開発について

このプラグインは、開発者が設計と品質を見ながら、AI（Anthropic 社の Claude）の手も借りて開発しています。詳細は [AI 利用ポリシー](AI_POLICY.md) にまとめています。

**開発**: Vill Yoshioka ([@villyoshioka](https://github.com/villyoshioka))
