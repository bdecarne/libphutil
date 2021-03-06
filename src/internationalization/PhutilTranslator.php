<?php

final class PhutilTranslator extends Phobject {

  private static $instance;

  private $locale;
  private $localeCode;
  private $shouldPostProcess;
  private $translations = array();

  public static function getInstance() {
    if (self::$instance === null) {
      self::$instance = new PhutilTranslator();
    }
    return self::$instance;
  }

  public static function setInstance(PhutilTranslator $instance) {
    self::$instance = $instance;
  }

  public function setLocale(PhutilLocale $locale) {
    $this->locale = $locale;
    $this->localeCode = $locale->getLocaleCode();
    $this->shouldPostProcess = $locale->shouldPostProcessTranslations();
    return $this;
  }

  /**
   * Add translations which will be later used by @{method:translate}.
   * The parameter is an array of strings (for simple translations) or arrays
   * (for translastions with variants). The number of items in the array is
   * language specific. It is `array($singular, $plural)` for English.
   *
   *   array(
   *     'color' => 'colour',
   *     '%d beer(s)' => array('%d beer', '%d beers'),
   *   );
   *
   * The arrays can be nested for strings with more variant parts:
   *
   *   array(
   *     '%d char(s) on %d row(s)' => array(
   *       array('%d char on %d row', '%d char on %d rows'),
   *       array('%d chars on %d row', '%d chars on %d rows'),
   *     ),
   *   );
   *
   * The translation should have the same placeholders as originals. Swapping
   * parameter order is possible:
   *
   *   array(
   *     '%s owns %s.' => '%2$s is owned by %1$s.',
   *   );
   *
   * @param array Identifier in key, translation in value.
   * @return PhutilTranslator Provides fluent interface.
   */
  public function setTranslations(array $translations) {
    $this->translations = $translations;
    return $this;
  }

  public function translate($text /* , ... */) {
    $translation = idx($this->translations, $text, $text);
    $args = func_get_args();
    while (is_array($translation)) {
      $arg = next($args);
      $translation = $this->chooseVariant($translation, $arg);
      if ($translation === null) {
        $pos = key($args);

        if (is_object($arg)) {
          $kind = get_class($arg);
        } else {
          $kind = gettype($arg);
        }

        return sprintf(
          '[Invalid Translation!] The "%s" language data offers variant '.
          'translations for the plurality or gender of argument %s, but '.
          'the value for that argument is not an integer, PhutilNumber, or '.
          'PhutilPerson (it is a value of type "%s"). Raw input: <%s>.',
          $this->localeCode,
          $pos,
          $kind,
          $text);
      }
    }
    array_shift($args);

    foreach ($args as $k => $arg) {
      if ($arg instanceof PhutilNumber) {
        $args[$k] = $this->formatNumber($arg->getNumber(), $arg->getDecimals());
      }
    }

    // Check if any arguments are PhutilSafeHTML. If they are, we will apply
    // any escaping necessary and output HTML.
    $is_html = false;
    foreach ($args as $arg) {
      if ($arg instanceof PhutilSafeHTML) {
        $is_html = true;
        break;
      }
    }

    if ($is_html) {
      foreach ($args as $k => $arg) {
        $args[$k] = (string)phutil_escape_html($arg);
      }
    }

    $result = vsprintf($translation, $args);
    if ($result === false) {
      // If vsprintf() fails (often because the translated string references
      // too many parameters), show the bad template with a note instead of
      // returning an empty string. This makes it easier to figure out what
      // went wrong and fix it.
      $result = pht('[Invalid Translation!] %s', $translation);
    }

    if ($this->shouldPostProcess) {
      $result = $this->locale->didTranslateString(
        $text,
        $translation,
        $args,
        $result);
    }

    if ($is_html) {
      $result = phutil_safe_html($result);
    }

    return $result;
  }

  private function chooseVariant(array $translations, $variant) {
    if (count($translations) == 1) {
      // If we only have one variant, we can select it directly.
      return reset($translations);
    }

    if ($variant instanceof PhutilNumber) {
      $is_sex = false;
      $variant = $variant->getNumber();
    } else if ($variant instanceof PhutilPerson) {
      $is_sex = true;
      $variant = $variant->getSex();
    } else if (is_int($variant)) {
      $is_sex = false;
    } else {
      return null;
    }

    // TODO: Move these into PhutilLocale if benchmarks show we aren't
    // eating too much of a performance cost.

    switch ($this->localeCode) {
      case 'en_US':
      case 'en_GB':
      case 'es_ES':
      case 'en_W*':
      case 'en_P*':
      case 'en_R*':
      case 'en_A*':
        list($singular, $plural) = $translations;
        if ($variant == 1) {
          return $singular;
        }
        return $plural;

      case 'cs_CZ':
        if ($is_sex) {
          list($male, $female) = $translations;
          if ($variant == PhutilPerson::SEX_FEMALE) {
            return $female;
          }
          return $male;
        }

        list($singular, $paucal, $plural) = $translations;
        if ($variant == 1) {
          return $singular;
        }
        if ($variant >= 2 && $variant <= 4) {
          return $paucal;
        }
        return $plural;

      case 'ko_KR':
        list($singular, $plural) = $translations;
        if ($variant == 1) {
          return $singular;
        }
        return $plural;

      default:
        throw new Exception(pht("Unknown locale '%s'.", $this->localeCode));
    }
  }

  /**
   * Translate date formatted by `$date->format()`.
   *
   * @param string Format accepted by `DateTime::format()`.
   * @param DateTime
   * @return string Formatted and translated date.
   */
  public function translateDate($format, DateTime $date) {
    static $format_cache = array();
    if (!isset($format_cache[$format])) {
      $translatable = 'DlSFMaA';
      preg_match_all(
        '/['.$translatable.']|(\\\\.|[^'.$translatable.'])+/',
        $format,
        $format_cache[$format],
        PREG_SET_ORDER);
    }

    $parts = array();
    foreach ($format_cache[$format] as $match) {
      $part = $date->format($match[0]);
      if (!isset($match[1])) {
        $part = $this->translate($part);
      }
      $parts[] = $part;
    }
    return implode('', $parts);
  }

  /**
   * Format number with grouped thousands and optional decimal part. Requires
   * translations of '.' (decimal point) and ',' (thousands separator). Both
   * these translations must be 1 byte long with PHP < 5.4.0.
   *
   * @param float
   * @param int
   * @return string
   */
  public function formatNumber($number, $decimals = 0) {
    return number_format(
      $number,
      $decimals,
      $this->translate('.'),
      $this->translate(','));
  }

  public function validateTranslation($original, $translation) {
    $pattern = '/<(\S[^>]*>?)?|&(\S[^;]*;?)?/i';
    $original_matches = null;
    $translation_matches = null;

    preg_match_all($pattern, $original, $original_matches);
    preg_match_all($pattern, $translation, $translation_matches);

    sort($original_matches[0]);
    sort($translation_matches[0]);

    if ($original_matches[0] !== $translation_matches[0]) {
      return false;
    }
    return true;
  }

}
