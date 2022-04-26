<?php

// https://en.wikipedia.org/wiki/ANSI_escape_code
// https://stackoverflow.com/questions/4842424/list-of-ansi-color-escape-sequences
// https://en.wikipedia.org/wiki/ANSI_escape_code#CSI_sequences

class Term {
    public const clear       = "\033[0m";

    public const red         = "\033[31m",
                 green       = "\033[32m",
                 brightWhite = "\033[97m",
                 brightBlack = "\033[90m";

    public const bgBlack        = "\033[40m",
                 bgRed          = "\033[41m",
                 bgBrightWhite  = "\033[107m",
                 bgBrightBlue   = "\033[48;5;33m",
                 bgBrightYellow = "\033[48;5;226m";

    public const bold        = "\033[2m",
                 noBold      = "\033[22m",
                 underline   = "\033[4m",
                 noUnderline = "\033[24m";

    public const moveHomeAndUp   = "\033[F",
                 moveDown        = "\033[E",
                 moveUp          = "\033[A",
                 moveRight       = "\033[C";

    public static function removeMessage($message)
    {
        $message = static::removeMarkup($message);
        $messageLines = mbSplitLines($message);
        $messageLinesReverse = array_reverse($messageLines);
        foreach ($messageLinesReverse as $key => $line) {
            $lineLength = strlen($line);
            echo str_repeat(chr(8), $lineLength);     // Move left
            echo str_repeat(' ',       $lineLength);     // Print emptiness
            if ($key !== array_key_last($messageLinesReverse)) {
                echo static::moveHomeAndUp;
            }
        }
    }

    public static function removeMarkup($text)
    {
        // https://stackoverflow.com/questions/14693701/how-can-i-remove-the-ansi-escape-sequences-from-a-string-in-python
        $ansiEscapeRegExp = <<<PhpRegExp
                            #\x1B(?:[@-Z\\-_]|\[[0-?]*[ -\/]*[@-~])#u
                            PhpRegExp;

        $text = preg_replace(trim($ansiEscapeRegExp), '', $text);
        return $text;
    }
}