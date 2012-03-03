<?php

namespace com\google\i18n\phonenumbers;

require_once dirname(__FILE__) . '/CountryCodeToRegionCodeMap.php';
require_once dirname(__FILE__) . '/PhoneMetadata.php';

/**
 * INTERNATIONAL and NATIONAL formats are consistent with the definition in ITU-T Recommendation
 * E123. For example, the number of the Google Switzerland office will be written as
 * "+41 44 668 1800" in INTERNATIONAL format, and as "044 668 1800" in NATIONAL format.
 * E164 format is as per INTERNATIONAL format but with no formatting applied, e.g. +41446681800.
 * RFC3966 is as per INTERNATIONAL format, but with all spaces and other separating symbols
 * replaced with a hyphen, and with any phone number extension appended with ";ext=".
 *
 * Note: If you are considering storing the number in a neutral format, you are highly advised to
 * use the PhoneNumber class.
 */
class PhoneNumberFormat {

	const E164 = 0;
	const INTERNATIONAL = 1;
	const NATIONAL = 2;
	const RFC3966 = 3;

}

/**
 * Type of phone numbers.
 */
class PhoneNumberType {

	const FIXED_LINE = 0;
	const MOBILE = 1;
	// In some regions (e.g. the USA), it is impossible to distinguish between fixed-line and
	// mobile numbers by looking at the phone number itself.
	const FIXED_LINE_OR_MOBILE = 2;
	// Freephone lines
	const TOLL_FREE = 3;
	const PREMIUM_RATE = 4;
	// The cost of this call is shared between the caller and the recipient, and is hence typically
	// less than PREMIUM_RATE calls. See // http://en.wikipedia.org/wiki/Shared_Cost_Service for
	// more information.
	const SHARED_COST = 5;
	// Voice over IP numbers. This includes TSoIP (Telephony Service over IP).
	const VOIP = 6;
	// A personal number is associated with a particular person, and may be routed to either a
	// MOBILE or FIXED_LINE number. Some more information can be found here:
	// http://en.wikipedia.org/wiki/Personal_Numbers
	const PERSONAL_NUMBER = 7;
	const PAGER = 8;
	// Used for "Universal Access Numbers" or "Company Numbers". They may be further routed to
	// specific offices, but allow one number to be used for a company.
	const UAN = 9;
	// A phone number is of type UNKNOWN when it does not fit any of the known patterns for a
	// specific region.
	const UNKNOWN = 10;

}

/**
 * Types of phone number matches. See detailed description beside the isNumberMatch() method.
 */
class MatchType {

	const NOT_A_NUMBER = 0;
	const NO_MATCH = 1;
	const SHORT_NSN_MATCH = 2;
	const NSN_MATCH = 3;
	const EXACT_MATCH = 4;

}

/**
 * Possible outcomes when testing if a PhoneNumber is possible.
 */
class ValidationResult {

	const IS_POSSIBLE = 0;
	const INVALID_COUNTRY_CODE = 1;
	const TOO_SHORT = 2;
	const TOO_LONG = 3;

}

/**
 * Utility for international phone numbers. Functionality includes formatting, parsing and
 * validation.
 *
 * <p>If you use this library, and want to be notified about important changes, please sign up to
 * our <a href="http://groups.google.com/group/libphonenumber-discuss/about">mailing list</a>.
 *
 * NOTE: A lot of methods in this class require Region Code strings. These must be provided using
 * ISO 3166-1 two-letter country-code format. These should be in upper-case. The list of the codes
 * can be found here: http://www.iso.org/iso/english_country_names_and_code_elements
 *
 * @author Shaopeng Jia
 * @author Lara Rennie
 */
class PhoneNumberUtil {

	const REGEX_FLAGS = 'ui'; //Unicode and case insensitive
	// The minimum and maximum length of the national significant number.
	const MIN_LENGTH_FOR_NSN = 3;
	const MAX_LENGTH_FOR_NSN = 15;

	// The maximum length of the country calling code.
	const MAX_LENGTH_COUNTRY_CODE = 3;

	// A mapping from a region code to the PhoneMetadata for that region.
	private $regionToMetadataMap = array();
	// A mapping from a country calling code for a non-geographical entity to the PhoneMetadata for
	// that country calling code. Examples of the country calling codes include 800 (International
	// Toll Free Service) and 808 (International Shared Cost Service).
	private $countryCodeToNonGeographicalMetadataMap = array();

	const REGION_CODE_FOR_NON_GEO_ENTITY = "001";
	const META_DATA_FILE_PREFIX = 'PhoneNumberMetadata';
	const TEST_META_DATA_FILE_PREFIX = 'PhoneNumberMetadataForTesting';

	private static $instance = NULL;
	private $supportedRegions = array();
	private $currentFilePrefix = self::META_DATA_FILE_PREFIX;

	const UNKNOWN_REGION = "ZZ";

	/**
	 * Gets a {@link PhoneNumberUtil} instance to carry out international phone number formatting,
	 * parsing, or validation. The instance is loaded with phone number metadata for a number of most
	 * commonly used regions.
	 *
	 * <p>The {@link PhoneNumberUtil} is implemented as a singleton. Therefore, calling getInstance
	 * multiple times will only result in one instance being created.
	 *
	 * @return PhoneNumberUtil instance
	 */
	public static function getInstance($baseFileLocation = self::META_DATA_FILE_PREFIX, array $countryCallingCodeToRegionCodeMap = NULL) {
		if ($countryCallingCodeToRegionCodeMap === NULL) {
			$countryCallingCodeToRegionCodeMap = CountryCodeToRegionCodeMap::$countryCodeToRegionCodeMap;
		}
		if (self::$instance == null) {
			self::$instance = new PhoneNumberUtil();
			self::$instance->countryCallingCodeToRegionCodeMap = $countryCallingCodeToRegionCodeMap;
			self::$instance->init($baseFileLocation);
			self::initExtnPatterns();
			self::initCapturingExtnDigits();
			self::initExtnPattern();
		}
		return self::$instance;
	}

	/**
	 * Used for testing purposes only to reset the PhoneNumberUtil singleton to null.
	 */
	public static function resetInstance() {
		self::$instance = NULL;
	}

	/**
	 * Convenience method to get a list of what regions the library has metadata for.
	 */
	public function getSupportedRegions() {
		return $this->supportedRegions;
	}

	/**
	 * This class implements a singleton, so the only constructor is private.
	 */
	private function __construct() {
		
	}

	private function init($filePrefix) {
		$this->currentFilePrefix = dirname(__FILE__) . '/data/' . $filePrefix;
		foreach ($this->countryCallingCodeToRegionCodeMap as $regionCodes) {
			$this->supportedRegions = array_merge($this->supportedRegions, $regionCodes);
		}
		//nanpaRegions.addAll(countryCallingCodeToRegionCodeMap.get(NANPA_COUNTRY_CODE));
	}

	/*
	  private void loadMetadataForRegionFromFile(String filePrefix, String regionCode) {
	  InputStream source =
	  PhoneNumberUtil.class.getResourceAsStream(filePrefix + "_" + regionCode);
	  ObjectInputStream in = null;
	  try {
	  in = new ObjectInputStream(source);
	  PhoneMetadataCollection metadataCollection = new PhoneMetadataCollection();
	  metadataCollection.readExternal(in);
	  for (PhoneMetadata metadata : metadataCollection.getMetadataList()) {
	  regionToMetadataMap.put(regionCode, metadata);
	  }
	  } catch (IOException e) {
	  LOGGER.log(Level.WARNING, e.toString());
	  } finally {
	  close(in);
	  }
	  }

	  private static void close(InputStream in) {
	  if (in != null) {
	  try {
	  in.close();
	  } catch (IOException e) {
	  LOGGER.log(Level.WARNING, e.toString());
	  }
	  }
	  }
	 */

	const PLUS_CHARS = '+＋';
	const RFC3966_EXTN_PREFIX = ";ext=";

	// We use this pattern to check if the phone number has at least three letters in it - if so, then
	// we treat it as a number where some phone-number digits are represented by letters.
	const VALID_ALPHA_PHONE_PATTERN = "(?:.*?[A-Za-z]){3}.*";

	// Regular expression of acceptable punctuation found in phone numbers. This excludes punctuation
	// found as a leading character only.
	// This consists of dash characters, white space characters, full stops, slashes,
	// square brackets, parentheses and tildes. It also includes the letter 'x' as that is found as a
	// placeholder for carrier information in some phone numbers. Full-width variants are also
	// present.
	/* "-x‐-―−ー－-／  <U+200B><U+2060>　()（）［］.\\[\\]/~⁓∼" */
	const VALID_PUNCTUATION = "-x\xE2\x80\x90-\xE2\x80\x95\xE2\x88\x92\xE3\x83\xBC\xEF\xBC\x8D-\xEF\xBC\x8F \xC2\xA0\xE2\x80\x8B\xE2\x81\xA0\xE3\x80\x80()\xEF\xBC\x88\xEF\xBC\x89\xEF\xBC\xBB\xEF\xBC\xBD.\\[\\]/~\xE2\x81\x93\xE2\x88\xBC";
	const DIGITS = "\\p{Nd}";

	private static $CAPTURING_EXTN_DIGITS;

	private static function initCapturingExtnDigits() {
		self::$CAPTURING_EXTN_DIGITS = "(" . self::DIGITS . "{1,7})";
	}

	private static $ALPHA_MAPPINGS = array(
		'A' => '2',
		'B' => '2',
		'C' => '2',
		'D' => '3',
		'E' => '3',
		'F' => '3',
		'G' => '4',
		'H' => '4',
		'I' => '4',
		'J' => '5',
		'K' => '5',
		'L' => '5',
		'M' => '6',
		'N' => '6',
		'O' => '6',
		'P' => '7',
		'Q' => '7',
		'R' => '7',
		'S' => '7',
		'T' => '8',
		'U' => '8',
		'V' => '8',
		'W' => '9',
		'X' => '9',
		'Y' => '9',
		'Z' => '9',
	);

	private static function getValidAlphaPattern() {
		// We accept alpha characters in phone numbers, ASCII only, upper and lower case.
		return preg_replace("[, \\[\\]]", "", implode(array_keys(self::$ALPHA_MAPPINGS))) . preg_replace("[, \\[\\]]", "", strtolower(implode(array_keys(self::$ALPHA_MAPPINGS))));
	}

	private static $EXTN_PATTERNS_FOR_PARSING;
	private static $EXTN_PATTERNS_FOR_MATCHING;

	private static function initExtnPatterns() {
		// One-character symbols that can be used to indicate an extension.
		$singleExtnSymbolsForMatching = "x\xEF\xBD\x98#\xEF\xBC\x83~\xEF\xBD\x9E";
		// For parsing, we are slightly more lenient in our interpretation than for matching. Here we
		// allow a "comma" as a possible extension indicator. When matching, this is hardly ever used to
		// indicate this.
		$singleExtnSymbolsForParsing = "," . $singleExtnSymbolsForMatching;

		self::$EXTN_PATTERNS_FOR_PARSING = self::createExtnPattern($singleExtnSymbolsForParsing);
		self::$EXTN_PATTERNS_FOR_MATCHING = self::createExtnPattern($singleExtnSymbolsForMatching);
	}

	/**
	 * Helper initialiser method to create the regular-expression pattern to match extensions,
	 * allowing the one-char extension symbols provided by {@code singleExtnSymbols}.
	 */
	private static function createExtnPattern($singleExtnSymbols) {
		// There are three regular expressions here. The first covers RFC 3966 format, where the
		// extension is added using ";ext=". The second more generic one starts with optional white
		// space and ends with an optional full stop (.), followed by zero or more spaces/tabs and then
		// the numbers themselves. The other one covers the special case of American numbers where the
		// extension is written with a hash at the end, such as "- 503#".
		// Note that the only capturing groups should be around the digits that you want to capture as
		// part of the extension, or else parsing will fail!
		// Canonical-equivalence doesn't seem to be an option with Android java, so we allow two options
		// for representing the accented o - the character itself, and one in the unicode decomposed
		// form with the combining acute accent.
		return (self::RFC3966_EXTN_PREFIX . self::$CAPTURING_EXTN_DIGITS . "|" . "[ \xC2\xA0\\t,]*" .
				"(?:e?xt(?:ensi(?:o\xCC\x81?|\xC3\xB3))?n?|(?:\xEF\xBD\x85)?\xEF\xBD\x98\xEF\xBD\x94(?:\xEF\xBD\x8E)?|" .
				"[" . $singleExtnSymbols . "]|int|\xEF\xBD\x89\xEF\xBD\x8E\xEF\xBD\x94|anexo)" .
				"[:\\.\xEF\xBC\x8E]?[ \xC2\xA0\\t,-]*" . self::$CAPTURING_EXTN_DIGITS . "#?|" .
				"[- ]+(" . self::DIGITS . "{1,5})#");
	}

	// Regular expression of viable phone numbers. This is location independent. Checks we have at
	// least three leading digits, and only valid punctuation, alpha characters and
	// digits in the phone number. Does not include extension data.
	// The symbol 'x' is allowed here as valid punctuation since it is often used as a placeholder for
	// carrier codes, for example in Brazilian phone numbers. We also allow multiple "+" characters at
	// the start.
	// Corresponds to the following:
	// plus_sign*([punctuation]*[digits]){3,}([punctuation]|[digits]|[alpha])*
	// Note VALID_PUNCTUATION starts with a -, so must be the first in the range.
	/**
	 * We append optionally the extension pattern to the end here, as a valid phone number may
	 * have an extension prefix appended, followed by 1 or more digits.
	 */
	private static function getValidPhoneNumberPattern() {
		return '%[' . self::PLUS_CHARS . ']*(?:[' . self::VALID_PUNCTUATION . ']*' . self::DIGITS . '){3,}[' .
				self::VALID_PUNCTUATION . self::getValidAlphaPattern() . self::DIGITS . "]*(?:" . self::$EXTN_PATTERNS_FOR_PARSING . ')?%' . self::REGEX_FLAGS;
	}

	/**
	 * Checks to see if the string of characters could possibly be a phone number at all. At the
	 * moment, checks to see that the string begins with at least 3 digits, ignoring any punctuation
	 * commonly found in phone numbers.
	 * This method does not require the number to be normalized in advance - but does assume that
	 * leading non-number symbols have been removed, such as by the method extractPossibleNumber.
	 *
	 * @param number  string to be checked for viability as a phone number
	 * @return boolean       true if the number could be a phone number of some sort, otherwise false
	 */
	public static function isViablePhoneNumber($number) {
		if (strlen($number) < self::MIN_LENGTH_FOR_NSN) {
			return FALSE;
		}
		$m = preg_match(self::getValidPhoneNumberPattern(), $number);
		return $m > 0;
	}

	/**
	 * Normalizes a string of characters representing a phone number. This performs
	 * the following conversions:
	 *   Punctuation is stripped.
	 *   For ALPHA/VANITY numbers:
	 *   Letters are converted to their numeric representation on a telephone
	 *       keypad. The keypad used here is the one defined in ITU Recommendation
	 *       E.161. This is only done if there are 3 or more letters in the number,
	 *       to lessen the risk that such letters are typos.
	 *   For other numbers:
	 *   Wide-ascii digits are converted to normal ASCII (European) digits.
	 *   Arabic-Indic numerals are converted to European numerals.
	 *   Spurious alpha characters are stripped.
	 *
	 * @param {string} number a string of characters representing a phone number.
	 * @return {string} the normalized string version of the phone number.
	 */
	public static function normalize($number) {
		$m = preg_match(self::getValidPhoneNumberPattern(), $number);
		if ($m > 0) {
			return $this->normalizeHelper($number, self::ALPHA_PHONE_MAPPINGS, true);
		} else {
			return $this->normalizeDigitsOnly($number);
		}
	}

	/**
	 * Normalizes a string of characters representing a phone number by replacing all characters found
	 * in the accompanying map with the values therein, and stripping all other characters if
	 * removeNonMatches is true.
	 *
	 * @param number                     a string of characters representing a phone number
	 * @param normalizationReplacements  a mapping of characters to what they should be replaced by in
	 *                                   the normalized version of the phone number
	 * @param removeNonMatches           indicates whether characters that are not able to be replaced
	 *                                   should be stripped from the number. If this is false, they
	 *                                   will be left unchanged in the number.
	 * @return  the normalized string version of the phone number
	 */
	private function normalizeHelper($number, array $normalizationReplacements, $removeNonMatches) {
		$normalizedNumber = "";
		$numberAsArray = str_split($number);
		foreach ($numberAsArray as $character) {
			if (isset($normalizationReplacements[strtoupper($character)])) {
				$normalizedNumber .= $normalizationReplacements[strtoupper($character)];
			} else if (!$removeNonMatches) {
				$normalizedNumber .= $character;
			}
			// If neither of the above are true, we remove this character.
		}
		return $normalizedNumber;
	}

	/**
	 * Normalizes a string of characters representing a phone number. This converts wide-ascii and
	 * arabic-indic numerals to European numerals, and strips punctuation and alpha characters.
	 *
	 * @param number  a string of characters representing a phone number
	 * @return        the normalized string version of the phone number
	 */
	public function normalizeDigitsOnly($number) {
		return $this->normalizeDigits($number, false /* strip non-digits */) . toString();
	}

	private function normalizeDigits($number, $keepNonDigits) {
		$normalizedDigits = "";
		$numberAsArray = str_split($number);
		foreach ($numberAsArray as $character) {
			if (is_int($character)) {
				$normalizedNumber .= $character;
			} else if ($keepNonDigits) {
				$normalizedNumber .= $character;
			}
			// If neither of the above are true, we remove this character.
		}
		return $normalizedDigits;
	}

	/**
	 * Returns the region where a phone number is from. This could be used for geocoding at the region
	 * level.
	 *
	 * @param number  the phone number whose origin we want to know
	 * @return  the region where the phone number is from, or null if no region matches this calling
	 *     code
	 */
	public function getRegionCodeForNumber(PhoneNumber $number) {
		$countryCode = $number->getCountryCode();
		if (!isset($this->countryCallingCodeToRegionCodeMap[$countryCode])) {
			//$numberString = $this->getNationalSignificantNumber($number);
			return NULL;
		}
		$regions = $this->countryCallingCodeToRegionCodeMap[$countryCode];
		if (count($regions) == 1) {
			return $regions[0];
		} else {
			return $this->getRegionCodeForNumberFromRegionList($number, $regions);
		}
	}

	/**
	 * Checks whether the country calling code is from a region whose national significant number
	 * could contain a leading zero. An example of such a region is Italy. Returns false if no
	 * metadata for the country is found.
	 */
	public function isLeadingZeroPossible($countryCallingCode) {
		$mainMetadataForCallingCode = $this->getMetadataForRegion(
				$this->getRegionCodeForCountryCode($countryCallingCode));
		if ($mainMetadataForCallingCode === NULL) {
			return FALSE;
		}
		return (bool) $mainMetadataForCallingCode->isLeadingZeroPossible();
	}

	/**
	 * Checks if the number is a valid vanity (alpha) number such as 800 MICROSOFT. A valid vanity
	 * number will start with at least 3 digits and will have three or more alpha characters. This
	 * does not do region-specific checks - to work out if this number is actually valid for a region,
	 * it should be parsed and methods such as {@link #isPossibleNumberWithReason} and
	 * {@link #isValidNumber} should be used.
	 *
	 * @param number  the number that needs to be checked
	 * @return  true if the number is a valid vanity number
	 */
	public function isAlphaNumber($number) {
		if (!$this->isViablePhoneNumber($number)) {
			// Number is too short, or doesn't match the basic phone number pattern.
			return false;
		}
		$this->maybeStripExtension($number);
		return (bool) preg_match('/' . self::VALID_ALPHA_PHONE_PATTERN . '/' . self::REGEX_FLAGS, $number);
	}

	// Regexp of all known extension prefixes used by different regions followed by 1 or more valid
	// digits, for use when parsing.
	private static $EXTN_PATTERN = NULL;

	private static function initExtnPattern() {
		self::$EXTN_PATTERN = "/(?:" . self::$EXTN_PATTERNS_FOR_PARSING . ")$/" . self::REGEX_FLAGS;
	}

	/**
	 * Strips any extension (as in, the part of the number dialled after the call is connected,
	 * usually indicated with extn, ext, x or similar) from the end of the number, and returns it.
	 *
	 * @param number  the non-normalized telephone number that we wish to strip the extension from
	 * @return        the phone extension
	 */
	private function maybeStripExtension(&$number) {
		$matches = array();
		$find = preg_match(self::$EXTN_PATTERN, $number, $matches, PREG_OFFSET_CAPTURE);
		// If we find a potential extension, and the number preceding this is a viable number, we assume
		// it is an extension.
		if ($find > 0 && $this->isViablePhoneNumber(substr($number, 0, $matches[0][1]))) {
			// The numbers are captured into groups in the regular expression.

			for ($i = 1, $length = count($matches); $i <= $length; $i++) {
				if ($matches[$i][0] != "") {
					// We go through the capturing groups until we find one that captured some digits. If none
					// did, then we will return the empty string.
					$extension = $matches[$i][0];
					$number = substr($number, 0, $matches[0][1]);
					return $extension;
				}
			}
		}
		return "";
	}

	/**
	 * Tests whether a phone number matches a valid pattern. Note this doesn't verify the number
	 * is actually in use, which is impossible to tell by just looking at a number itself.
	 *
	 * @param number       the phone number that we want to validate
	 * @return boolean that indicates whether the number is of a valid pattern
	 */
	public function isValidNumber(PhoneNumber $number) {
		$regionCode = $this->getRegionCodeForNumber($number);
		return $this->isValidNumberForRegion($number, $regionCode);
	}

	/**
	 * Returns the region code that matches the specific country calling code. In the case of no
	 * region code being found, ZZ will be returned. In the case of multiple regions, the one
	 * designated in the metadata as the "main" region for this calling code will be returned.
	 */
	public function getRegionCodeForCountryCode($countryCallingCode) {
		$regionCodes = isset($this->countryCallingCodeToRegionCodeMap[$countryCallingCode]) ? $this->countryCallingCodeToRegionCodeMap[$countryCallingCode] : NULL;
		return $regionCodes === NULL ? self::UNKNOWN_REGION : $regionCodes[0];
	}

	/**
	 * Tests whether a phone number is valid for a certain region. Note this doesn't verify the number
	 * is actually in use, which is impossible to tell by just looking at a number itself. If the
	 * country calling code is not the same as the country calling code for the region, this
	 * immediately exits with false. After this, the specific number pattern rules for the region are
	 * examined. This is useful for determining for example whether a particular number is valid for
	 * Canada, rather than just a valid NANPA number.
	 *
	 * @param PhoneNumber number       the phone number that we want to validate
	 * @param string regionCode   the region that we want to validate the phone number for
	 * @return boolean that indicates whether the number is of a valid pattern
	 */
	public function isValidNumberForRegion(PhoneNumber $number, $regionCode) {
		$countryCode = $number->getCountryCode();
		$metadata = $this->getMetadataForRegionOrCallingCode($countryCode, $regionCode);
		if (($metadata === NULL) ||
				(!self::REGION_CODE_FOR_NON_GEO_ENTITY === $regionCode &&
				$countryCode != $this->getCountryCodeForValidRegion($regionCode))) {
			// Either the region code was invalid, or the country calling code for this number does not
			// match that of the region code.
			return false;
		}
		$generalNumDesc = $metadata->getGeneralDesc();
		$nationalSignificantNumber = $this->getNationalSignificantNumber($number);

		// For regions where we don't have metadata for PhoneNumberDesc, we treat any number passed in
		// as a valid number if its national significant number is between the minimum and maximum
		// lengths defined by ITU for a national significant number.
		if (!$generalNumDesc->hasNationalNumberPattern()) {
			$numberLength = strlen($nationalSignificantNumber);
			return $numberLength > self::MIN_LENGTH_FOR_NSN && $numberLength <= self::MAX_LENGTH_FOR_NSN;
		}
		return $this->getNumberTypeHelper($nationalSignificantNumber, $metadata) != PhoneNumberType::UNKNOWN;
	}

	private function getMetadataForRegionOrCallingCode($countryCallingCode, $regionCode) {
		return self::REGION_CODE_FOR_NON_GEO_ENTITY === $regionCode ? $this->getMetadataForNonGeographicalRegion($countryCallingCode) : $this->getMetadataForRegion($regionCode);
	}

	/**
	 *
	 * @param string $regionCode
	 * @return PhoneMetadata 
	 */
	public function getMetadataForRegion($regionCode) {
		if (!$this->isValidRegionCode($regionCode)) {
			return null;
		}

		if (!isset($this->regionToMetadataMap[$regionCode])) {
			// The regionCode here will be valid and won't be '001', so we don't need to worry about
			// what to pass in for the country calling code.
			$this->loadMetadataFromFile($this->currentFilePrefix, $regionCode, 0);
		}
		return isset($this->regionToMetadataMap[$regionCode]) ? $this->regionToMetadataMap[$regionCode] : NULL;
	}

	/**
	 * Helper function to check region code is not unknown or null.
	 */
	private function isValidRegionCode($regionCode) {
		return $regionCode != null && in_array($regionCode, $this->supportedRegions);
	}

	private function getRegionCodeForNumberFromRegionList(PhoneNumber $number, array $regionCodes) {
		$nationalNumber = $this->getNationalSignificantNumber($number);
		foreach ($regionCodes as $regionCode) {
			// If leadingDigits is present, use this. Otherwise, do full validation.
			$metadata = $this->getMetadataForRegion($regionCode);
			if ($metadata->hasLeadingDigits()) {
				$nbMatches = preg_match('/' . $metadata->getLeadingDigits() . '/', $nationalNumber, $matches, PREG_OFFSET_CAPTURE);
				if ($nbMatches > 0 && $matches[0][1] === 0) {
					return $regionCode;
				}
			} else if ($this->getNumberTypeHelper($nationalNumber, $metadata) != PhoneNumberType::UNKNOWN) {
				return $regionCode;
			}
		}
		return NULL;
	}

	/**
	 * Gets the national significant number of the a phone number. Note a national significant number
	 * doesn't contain a national prefix or any formatting.
	 *
	 * @param number  the phone number for which the national significant number is needed
	 * @return  the national significant number of the PhoneNumber object passed in
	 */
	public function getNationalSignificantNumber(PhoneNumber $number) {
		// If a leading zero has been set, we prefix this now. Note this is not a national prefix.
		$nationalNumber = $number->isItalianLeadingZero() ? "0" : "";
		$nationalNumber .= $number->getNationalNumber();
		return $nationalNumber;
	}

	private function loadMetadataFromFile($filePrefix, $regionCode, $countryCallingCode) {
		$isNonGeoRegion = self::REGION_CODE_FOR_NON_GEO_ENTITY === $regionCode;
		$source = $isNonGeoRegion ? $filePrefix . "_" . $countryCallingCode : $filePrefix . "_" . $regionCode;
		if (is_readable($source)) {
			$data = include $source;
			$metadata = new PhoneMetadata();
			$metadata->fromArray($data);
			if ($isNonGeoRegion) {
				$this->countryCodeToNonGeographicalMetadataMap[$countryCallingCode] = $metadata;
			} else {
				$this->regionToMetadataMap[$regionCode] = $metadata;
			}
		}
	}

	private function getNumberTypeHelper($nationalNumber, PhoneMetadata $metadata) {
		$generalNumberDesc = $metadata->getGeneralDesc();
		if (!$generalNumberDesc->hasNationalNumberPattern() ||
				!$this->isNumberMatchingDesc($nationalNumber, $generalNumberDesc)) {
			return PhoneNumberType::UNKNOWN;
		}
		if ($this->isNumberMatchingDesc($nationalNumber, $metadata->getPremiumRate())) {
			return PhoneNumberType::PREMIUM_RATE;
		}
		if ($this->isNumberMatchingDesc($nationalNumber, $metadata->getTollFree())) {
			return PhoneNumberType::TOLL_FREE;
		}

		/*
		 * @todo Implement other phone desc
		  if ($this->isNumberMatchingDesc($nationalNumber, $metadata->getSharedCost())) {
		  return PhoneNumberType::SHARED_COST;
		  }
		  if ($this->isNumberMatchingDesc($nationalNumber, $metadata->getVoip())) {
		  return PhoneNumberType::VOIP;
		  }
		  if ($this->isNumberMatchingDesc($nationalNumber, $metadata->getPersonalNumber())) {
		  return PhoneNumberType::PERSONAL_NUMBER;
		  }
		  if ($this->isNumberMatchingDesc($nationalNumber, $metadata->getPager())) {
		  return PhoneNumberType::PAGER;
		  }
		  if ($this->isNumberMatchingDesc($nationalNumber, $metadata->getUan())) {
		  return PhoneNumberType::UAN;
		  }
		  if ($this->isNumberMatchingDesc($nationalNumber, $metadata->getVoicemail())) {
		  return PhoneNumberType::VOICEMAIL;
		  }
		 */
		$isFixedLine = $this->isNumberMatchingDesc($nationalNumber, $metadata->getFixedLine());
		if ($isFixedLine) {
			if ($metadata->isSameMobileAndFixedLinePattern()) {
				return PhoneNumberType::FIXED_LINE_OR_MOBILE;
			} else if ($this->isNumberMatchingDesc($nationalNumber, $metadata->getMobile())) {
				return PhoneNumberType::FIXED_LINE_OR_MOBILE;
			}
			return PhoneNumberType::FIXED_LINE;
		}
		// Otherwise, test to see if the number is mobile. Only do this if certain that the patterns for
		// mobile and fixed line aren't the same.
		if (!$metadata->isSameMobileAndFixedLinePattern() &&
				$this->isNumberMatchingDesc($nationalNumber, $metadata->getMobile())) {
			return PhoneNumberType::MOBILE;
		}
		return PhoneNumberType::UNKNOWN;
	}

	private function isNumberMatchingDesc($nationalNumber, PhoneNumberDesc $numberDesc) {
		$possibleNumberPatternMatcher = preg_match('/' . str_replace(array(PHP_EOL, ' '), '', $numberDesc->getPossibleNumberPattern()) . '/', $nationalNumber);
		$nationalNumberPatternMatcher = preg_match('/' . str_replace(array(PHP_EOL, ' '), '', $numberDesc->getNationalNumberPattern()) . '/', $nationalNumber);
		return $possibleNumberPatternMatcher && $nationalNumberPatternMatcher;
	}

	public function getMetadataForNonGeographicalRegion($countryCallingCode) {
		if (!isset($this->countryCallingCodeToRegionCodeMap[$countryCallingCode])) {
			return null;
		}
		if (!isset($this->countryCodeToNonGeographicalMetadataMap[$countryCallingCode])) {
			$this->loadMetadataFromFile($this->currentFilePrefix, self::REGION_CODE_FOR_NON_GEO_ENTITY, $countryCallingCode);
		}
		return $this->countryCodeToNonGeographicalMetadataMap[$countryCallingCode];
	}

}