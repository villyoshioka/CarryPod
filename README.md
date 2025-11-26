# Static Generation Engine

**WordPress サイトを静的サイトに変換するプラグイン。**

[![License: GPLv3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)](https://www.php.net/)
[![Version](https://img.shields.io/badge/Version-1.1.0-green.svg)](https://github.com/villyoshioka/static-generation-engine/releases)

> **注意**: **このプラグインについて、コードは公開していますが、サポートは行っていません。使用は自己責任でお願いします。**

---

## これは何？

WordPress で作ったサイトを、普通の HTML ファイルに変換するプラグインです。

### 主な機能

- WordPress のページや記事を HTML ファイルに変換
- 画像や CSS なども一緒に保存
- 変更部分だけ更新する差分検出機能
- 自動静的化機能

### 保存先

以下の方法から選べます：

- **GitHub**: GitHub API で直接プッシュ
- **ローカル Git**: Git リポジトリとして保存
- **ZIP ファイル**: ZIP ファイルとして保存
- **ローカルディレクトリ**: 指定したフォルダに保存

---

## インストール

1. [Releases](https://github.com/villyoshioka/StaticGenerationEngine/releases) から ZIP ファイルをダウンロード
2. WordPress の管理画面で「プラグイン」→「新規追加」→「プラグインのアップロード」
3. ダウンロードした ZIP ファイルを選択してインストール
4. 「有効化」をクリック

---

## 使い方

プラグインを有効化すると、WordPress 管理画面に「Static Generation Engine」メニューが追加されます。

1. **設定画面**で出力先を選択（GitHub / ローカル Git / ZIP / ディレクトリ）
2. **実行画面**で「静的化を実行」ボタンをクリック
3. 進捗を確認しながら完了を待つ。結構時間かかりますので、その間にお茶でもどうぞ。

---

## 使用ライブラリ

- [Action Scheduler](https://actionscheduler.org/)（GPLv3）- バックグラウンド処理に使用

## ライセンス

このプラグインは GPLv3 ライセンスで公開されています。[WP2Static](https://github.com/elementor/wp2static)（Unlicense）からインスパイアされ開発されました。

---

## 開発について

このプラグインは、Claude（Anthropic 社の AI）を用いて実装されました。設計・仕様策定・品質管理は開発者が行っています。

**開発**: Vill Yoshioka ([@villyoshioka](https://github.com/villyoshioka))
