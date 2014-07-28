GifManipulator
==============

PHP-GDで扱えないアニメーションGIFのリサイズや画像スライスをサポートするライブラリクラスです。

GIFイメージファイルを仕様に沿って解析するので、精密な中身の検証などができるかもしれません。

## Usage

クラスをロードして、staticメソッドからインスタンスを生成します。
その後、publicなメソッドを介してイメージバイナリを操作します。
（メソッドリファレンスは後述）

```
<?php

require_once('GifManipulator.php');

// インスタンスの生成
$gif = GifManipulator::createFromFile('/path/to/image.gif');

// リサイズ
$gif->resize(100, 50);

// 保存
$gif->save('/path/to/image_resized.gif');
```

## Notice

GIF89aのSpecで定義されている仕様通りのものは大抵解析できますが、
生成するツールによって、規格外のアニメーションGIFが生成されることを確認しています。
できるだけ規格に沿っていないものもパースできるように頑張っていますが、全て把握しきれてないのが現状です。
もし解析エラーになる画像ファイルがある場合、教えて頂けると嬉しいです。

### Comment Extension / Plain Text Extensionについて

GIF89a Specでは`Comment Extension`および`Plain Text Extension`が策定されていますが、
このライブラリではリサイズや画像スライスの際にこれらのエクステンションデータは無視されます（現状、これらのセクションが画像にどのような影響を与えているのか未検証のため）

---

## メソッドリファレンス（publicのみ）

### GifManipulator GIfManipulator::createFromFile(string $filePath)

引数のファイルパスからインスタンスを生成します。

### GifManipulator GIfManipulator::createFromBInary(string $binary)

引数のバイナリ文字列からインスタンスを生成します。

### void Gifmanipulator->display()

画像バイナリをヘッダ（Content-Type: image/gif）と共に標準出力します。

### Mixed GifManipulator->slice()

複数画像から構成されているファイルの場合、メソッドをコールする度に画像が切り出され、GifManipulatorインスタンスが戻ります。
画像がそれ以上切り出されない場合はNULLが戻ります。

### Resouce GifManipulator->toImage()

画像バイナリからGDのリソースを生成して返却します。

### bool GifManipulator->isAnimated()

アニメーションGIFであるかどうかを判定します（内部ではNetSacpe Extensionブロックがあるかどうかのみを検証しています）

### bool GifManipulator->save(string $filePath)

引数のファイルにGIFイメージを保存します。成功すればTRUE、失敗したらFALSEが戻ります。

### GifManipulator GifManipulator->resize(int $x, int $y)

横幅$x、縦幅$yにリサイズします。アニメーションGIFの場合でもアニメーションを保持したままリサイズ可能です。
ただし、与えられたサイズに強制リサイズするので、縦横比は保持しません。

### GifManipulator GifManipulator->resizeRatio(int $rate)

引数の%にリサイズを行います。引数は%の数値で与えてください。

### stdClass GifManipulator->getSize()

画像のサイズ（width、heightをプロパティに持つ）オブジェクトを取得します。

## Licence

MIT Licence.

## TODO

画像部分は厳密にLZW圧縮方式に基づいて解析できていないので、
今後エンコーダ/デコーダを作らないといけないと思う。
