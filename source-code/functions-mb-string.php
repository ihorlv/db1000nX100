<?php

function mbDirname(?string $path) : string
{
    $path = mbTrimDir($path);
    $parts = mb_split("[/\\\\]", $path);
    $partsCount = count($parts);
    if ($partsCount > 1) {
        unset($parts[$partsCount - 1]);
    }
    return implode('/', $parts);
}

function mbFilename(?string $path) : string
{
    $baseName = mbBasename($path);
    if (($pos = mb_strrpos($baseName, '.')) === false) {
        return $baseName;
    } else {
        return mb_substr($baseName, 0, $pos);
    }
}

function mbBasename(?string $path) : string
{
    $parts = mb_split("[/\\\\]", (string) $path);
    $partsCount = count($parts);
    if ($partsCount > 1) {
        return $parts[$partsCount - 1];
    } else {
        return $path;
    }
}

function mbTrim(?string $str, string $charactersMask = '') : string
{
    $str = mbLTrim($str, $charactersMask);
    $str = mbRTrim($str, $charactersMask);
    return $str;
}

function mbLTrim(?string $str, string $charactersMask = '') : string
{
    if ($charactersMask) {
        $pattern = '/^[';
        $pattern .= mbPregQuote($charactersMask, '/');
        $pattern .= ']+/u';
    } else {
        $pattern = '/^\s+/u';
    }

    return preg_replace($pattern, '', (string) $str);
}

function mbRTrim(?string $str, string $charactersMask = '') : string
{
    if ($charactersMask) {
        $pattern = '/[';
        $pattern .= mbPregQuote($charactersMask, '/');
        $pattern .= ']+$/u';
    } else {
        $pattern = '/\s+$/u';
    }

    return preg_replace($pattern, '', (string) $str);
}

function mbPregQuote(?string $str, string $delimiter = '') : string
{
    $ret = '';

    $specialChars = '\.+*?[^]$(){}=!<>|:-';
    if ($delimiter) {
        $specialChars .= $delimiter;
    }

    for ($ci = 0; $ci < mb_strlen($str); $ci++) {
        $c = mb_substr($str, $ci, 1);
        if (mb_strpos($specialChars, $c) !== false) {
            $c = "\\" . $c;
        }
        $ret .= $c;
    }

    return $ret;
}

function mbExplode(string $separator, ?string $string) : array
{
    if (! $string) {
        return [];
    }

    $separator = mbPregQuote($separator);
    return mb_split($separator, $string);
}

function mbSplitLines(?string $string) : array
{
    if (! $string) {
        return [];
    }

    $newLineRegExp = <<<PhpRegExp
        (\r\n|\r|\n)
        PhpRegExp;

    $arr = mb_split(trim($newLineRegExp), $string);
    return $arr ? $arr : [];
}

function mbSeparateNLines(?string $text, int $linesCount = 1, $endOfLineChar = PHP_EOL) : \stdClass
{
    $nLinesArray    = [];
    $restLinesArray = [];

    $lines = mbSplitLines($text);
    foreach ($lines as $i => $line) {
        if ($i < $linesCount) {
            $nLinesArray[] = $line;
        } else {
            $restLinesArray[] = $line;
        }
    }

    return (object) [
        'nLines'    => implode($endOfLineChar, $nLinesArray),
        'restLines' => implode($endOfLineChar, $restLinesArray)
    ];
}

function mbRemoveEmptyLinesFromArray(array $array, bool $reIndex = true) : array
{
    $array = array_filter(
        $array,
        function ($item) {
            if (mb_strlen(mbTrim((string) $item))) {
                return true;
            } else {
                return false;
            }
        }
    );

    if ($reIndex) {
        $array = array_values($array);
    }
    return $array;
}

function mbPathWithoutExt(?string $path) : string
{
    $baseName = mbBasename($path);
    if (mb_strpos($baseName, '.') === false) {
        return $path;
    } else {
        $pos = mb_strrpos($path, '.');
        return mb_substr($path, 0, $pos);
    }
}

function mbExt(?string $path) : string
{
    $baseName = mbBasename($path);
    if (($pos = mb_strrpos($baseName, '.')) === false) {
        return '';
    } else {
        return mb_substr($baseName, $pos + 1);
    }
}

function mbStrReplace($search, $replace, $subject, int &$count = 0) : string
{
    if (is_array($subject)) {
        foreach ($subject as $key => $value) {
            $subject[$key] = mbStrReplace($search, $replace, $value, $count);
        }
    } else {
        $searches = is_array($search) ? array_values($search) : [$search];
	    if (is_array($replace)) {
            $replacements = array_values($replace);
        } else {
            $replacements = array_pad([], count($searches), $replace);
        }
	    foreach ($searches as $key => $search) {
            $parts = mb_split(mbPregQuote($search), $subject);
            $count += count($parts) - 1;
            $subject = implode($replacements[$key], $parts);
        }
	}
    return $subject;
}

function mbPathWithoutRoot(?string $absolutePath, ?string $root = __DIR__, bool $trimFirstSlash = false) : string
{
    $root = mbTrimDir($root);

    if (mb_strpos($absolutePath, $root) === 0) {
        $ret = mb_substr($absolutePath, strlen($root));

        if ($trimFirstSlash) {
            $ret = mbLTrim($ret, '/\\');
        }

        if (mb_strlen($ret) === 0) {
            $ret = "./";
        }

        return $ret;
    } else {
        return false;
    }
}

function mbTrimDir(?string $dirPath) : string
{
    $ret = preg_replace('#^\s+#u', '',       (string) $dirPath);
    $ret = preg_replace('#[\s/\\\]+$#u', '', (string) $dirPath);
    return $ret;
}

function mbStrPad(?string $str, int $returnLength, string $padString = ' ', int $padType = STR_PAD_RIGHT) : string
{
    $strLength = mb_strlen(Term::removeMarkup($str));
    if ($returnLength <= $strLength) {
        return $str;
    }
    $repeatLength = $returnLength - $strLength;
    $padding = str_repeat($padString, ceil($repeatLength / mb_strlen($padString)));

    switch ($padType) {

        case STR_PAD_BOTH:
            $middleChar = floor($repeatLength / 2);
            $paddingLeft = mb_substr($padding, 0, $middleChar);
            $paddingRight = mb_substr($padding, $middleChar, $repeatLength - $middleChar);
            return $paddingLeft . $str . $paddingRight;

        case STR_PAD_LEFT:
            $padding = mb_substr($padding, 0, $repeatLength);
            return $padding . $str;

        default:
            $padding = mb_substr($padding, 0, $repeatLength);
            return $str . $padding;
    }
}