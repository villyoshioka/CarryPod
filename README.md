# Carry Pod

**WordPress サイトを静的サイトに変換するプラグイン。**

[![License: GPLv3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)](https://www.php.net/)
[![Version](https://img.shields.io/badge/Version-1.4.1-green.svg)](https://github.com/villyoshioka/CarryPod/releases)

> **注意**: **このプラグインについて、コードは公開していますが、サポートは行っていません。**

---

## これは何？

WordPress で作ったサイトを、静的な HTML や CSS などのファイルに変換するプラグインです。

### 主な機能

- WordPress のページや記事を HTML ファイルに変換
- 画像や CSS なども一緒に出力
- 変更部分だけ更新する差分検出機能（GitHub / GitLab）
- 自動静的化機能

### 出力先

以下から選べます：

- **GitHub**: GitHub API で直接プッシュ
- **GitLab**: GitLab API で直接プッシュ
- **Cloudflare Workers**: Cloudflare Workers にデプロイ
- **Netlify**: Netlify にデプロイ
- **ローカル Git**: Git リポジトリとして出力
- **ZIP ファイル**: ZIP ファイルとして出力
- **ローカルディレクトリ**: 指定したフォルダに出力

---

## インストール

1. [Releases](https://github.com/villyoshioka/CarryPod/releases) から ZIP ファイルをダウンロード
2. WordPress の管理画面で「プラグイン」→「新規追加」→「プラグインのアップロード」
3. ダウンロードした ZIP ファイルを選択してインストール
4. 「有効化」をクリック

### アップグレード（v1.2.0 以前からの場合）

v1.2.0 以前のバージョンからは自動更新が正しく動作しない場合があります。その場合は以下の手順で手動アップグレードしてください：

1. 既存のプラグインを無効化（削除は不要）
2. [Releases](https://github.com/villyoshioka/CarryPod/releases) から最新の ZIP ファイルをダウンロード
3. 「プラグイン」→「新規追加」→「プラグインのアップロード」で ZIP をアップロード
4. 「今すぐインストール」→「既存のプラグインと置き換える」を選択
5. 「有効化」をクリック

v1.3.1 以降は自動更新が正常に動作します。

---

## 使い方

プラグインを有効化すると、WordPress 管理画面に「Carry Pod」メニューが追加されます。

1. **設定画面**で出力先を選択します。（GitHub / GitLab / Cloudflare Workers / Netlify / ローカル Git / ZIP / ディレクトリ）
2. **実行画面**で「静的化を実行」ボタンをクリックします。
3. 進捗を確認しながら完了を待ちます。結構時間がかかる場合がありますので、その間に水羊羹でもどうぞ。

### デバッグモード

詳細なログを確認したい場合は、URL に `&debugmode=on` を追加してデバッグモードを有効化できます。無効にするには `&debugmode=off` を追加してください。

---

## 使用ライブラリ

- [Action Scheduler](https://actionscheduler.org/)（GPLv3）- バックグラウンド処理に使用

## ライセンス

このプラグインは GPLv3 ライセンスで公開されています。[WP2Static](https://github.com/elementor/wp2static)（Unlicense）からインスパイアされ開発されました。

---

## プライバシーについて

このプラグインは WordPress サイトを静的ファイルに変換する機能のみを提供します。

- ユーザーデータの収集・解析は行っていません
- トラッキング機能は含まれていません

---

## 開発について

このプラグインは、Claude（Anthropic 社の AI）を用いて実装されました。設計・仕様策定・品質管理は開発者が行っています。

詳細は [AI 利用ポリシー](AI_POLICY.md) をご覧ください。

**開発**: Vill Yoshioka ([@villyoshioka](https://github.com/villyoshioka))
