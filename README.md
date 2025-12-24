# Carry Pod

**WordPress サイトを静的サイトに変換するプラグイン。**

[![License: GPLv3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)](https://www.php.net/)
[![Version](https://img.shields.io/badge/Version-2.0.0-green.svg)](https://github.com/villyoshioka/CarryPod/releases)

> **注意**: **このプラグインについて、コードは公開していますが、サポートは行っていません。**

---

## これは何？

WordPress で作ったサイトを、静的な HTML や CSS などのファイルに変換するプラグインです。

### 主な機能

- WordPress のページや記事を HTML ファイルに変換
- GitHub / GitLab / Cloudflare Workers / Netlify など複数の出力先に対応
- 自動静的化機能
- wp-includes / wp-content を任意の名称に変更可能

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

### v2.0.0へのアップグレード

**重要**: v2.0.0は内部名称を大幅に変更した破壊的変更を含むため、v1.x系からの自動アップデートは提供しません。以下の手順で手動アップグレードしてください：

1. **設定のエクスポート**: 現在の設定を「設定のエクスポート」機能でバックアップ
2. 既存のプラグインを無効化して削除
3. [Releases](https://github.com/villyoshioka/CarryPod/releases) から v2.0.0 の ZIP ファイルをダウンロード
4. 「プラグイン」→「新規追加」→「プラグインのアップロード」で ZIP をアップロード
5. 「今すぐインストール」を選択
6. 「有効化」をクリック
7. **設定のインポート**: バックアップした設定をインポート

v2.0.0以降は自動更新が利用できます。

---

## 使い方

プラグインを有効化すると、WordPress 管理画面に「Carry Pod」メニューが追加されます。

1. **設定画面**で出力先を選択します（GitHub / GitLab / Cloudflare Workers / Netlify / ローカル Git / ZIP / ディレクトリ）
2. 必要に応じて、カスタムフォルダ名、追加・除外ファイル、URL形式などの設定を行います
3. **実行画面**で「静的化を実行」ボタンをクリックします
4. 進捗を確認しながら完了を待ちます（時間がかかる場合がありますので、その間に桜餅でもどうぞ）

### デバッグモード

詳細なログを確認したい場合は、URL に `&debugmode=on` を追加してデバッグモードを有効化できます。無効にするには `&debugmode=off` を追加してください。

---

## ライセンスと使用モジュール

このプラグインは GPLv3 ライセンスで公開されています。[WP2Static](https://github.com/elementor/wp2static)（Unlicense）からインスパイアされ開発されました。

バックグラウンド処理には [Action Scheduler](https://actionscheduler.org/)（GPLv3）を使用しています。

---

## プライバシーについて

このプラグインは WordPress サイトを静的ファイルに変換する機能のみを提供します。

- ユーザーデータの収集・解析なし
- トラッキング機能なし

---

## 開発について

このプラグインは、Claude（Anthropic 社の AI）を用いて実装されました。設計・仕様策定・品質管理は開発者が行っています。

詳細は [AI 利用ポリシー](AI_POLICY.md) をご覧ください。

**開発**: Vill Yoshioka ([@villyoshioka](https://github.com/villyoshioka))
