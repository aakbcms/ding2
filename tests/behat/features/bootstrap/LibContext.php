<?php

use Behat\Behat\Context\Context;
use Behat\Behat\Context\SnippetAcceptingContext;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use Behat\Mink\Element\ElementInterface;
use Behat\MinkExtension\Context\MinkContext;
use Behat\Mink\Driver\Selenium2Driver;
use Behat\Mink\Exception\UnsupportedDriverActionException;
use Page\SearchPage;
use Page\ObjectPage;


/**
 * Defines application features from the specific context.
 */
class LibContext implements Context, SnippetAcceptingContext {

  /** @var array
   * is holding css-locator strings
   */
  public $cssStr;

  /**
   * @var string
   */
  private $currentFeature;

  /**
   * @var string
   */
  private $currentScenario;

  /** @var \Drupal\DrupalExtension\Context\DrupalContext */
  public $drupalContext;

  /**
   * @var string
   * Contains the last search string we used
   */
  public $lastSearchString;

  /** @var \Drupal\DrupalExtension\Context\MinkContext */
  public $minkContext;


  /**
   * Current authenticated user.
   * A value of FALSE denotes an anonymous user.
   *
   * @var stdClass|bool
   */
  public $user = FALSE;

  /** @var object
   * Holds the flags telling whether we want a very verbose run or a more silent one
   */
  public $verbose;



  /**
   * Initializes context.
   *
   * Every scenario gets its own context instance.
   * You can also pass arbitrary arguments to the
   * context constructor through behat.yml.
   */
  public function __construct(SearchPage $searchPage, DataManager $dataManager, ObjectPage $objectPage) {
    $this->searchPage = $searchPage;
    $this->dataMgr = $dataManager;
    $this->objectPage = $objectPage;

    // Initialise the verbose structure. These are default settings.
    $this->verbose = (object) array (
          'loginInfo' => true,
    );




  }

  /**
   * @BeforeScenario
   *
   * @param BeforeScenarioScope $scope
   * @throws \Behat\Mink\Exception\DriverException
   */
  public function beforeScenario(BeforeScenarioScope $scope) {
    // Gather contexts.
    $environment = $scope->getEnvironment();
    $this->currentFeature = $scope->getFeature()->getTitle();
    $this->currentScenario = $scope->getScenario()->getTitle();

    $this->drupalContext = $environment->getContext('Drupal\DrupalExtension\Context\DrupalContext');
    $this->minkContext = $environment->getContext('Drupal\DrupalExtension\Context\MinkContext');

    // Try to set a default window size. 
    try {
      $this->minkContext->getSession()
            ->getDriver()
            ->resizeWindow(1024, 2000, 'current');
    } catch (UnsupportedDriverActionException $e) {
      // Ignore, but make a note of it for the tester.
      print_r("Before Scenario: resizeWindow fejlede. \n");
    }
  }

  /**
   * @AfterScenario
   * Place screenshots in the assigned folder, after resizing window appropriately, if scenario failed
   *
   */
  public function afterScenario(\Behat\Behat\Hook\Scope\AfterScenarioScope $scope) {


    if ($scope->getTestResult()->getResultCode() > 0) {
      $this->saveScreenshot();
    }
  }

  /**
   * @Then I save (a) screenshot
   *
   * Save a screenshot on demand
   */
  public function saveScreenshot() {
    // Setup folders and make sure the folders exists.
    $screenShotDir = 'results/';
    $featureFolder = preg_replace('/\W/', '', $this->currentFeature);
    if (!file_exists($screenShotDir . $featureFolder)) {
      mkdir($screenShotDir . $featureFolder);
    }

    // Setup filename and make sure it is unique, by adding a postfix (simple number).
    $fileName = $screenShotDir . $featureFolder . "/"
          . preg_replace('/\W/', '', $this->currentScenario) ;
    $fileNamePostfix = "";
    while (file_exists($fileName . $fileNamePostfix . '.png')) {
      $fileNamePostfix++;
    }
    $fileName = $fileName . $fileNamePostfix . '.png';

    // Log the filename of the screenshot to notify the user.
    print_r( "Screenshot in: " . $fileName . "\n");

    // Now find the actual height of the shown page.
    $height = $this->minkContext->getSession()->evaluateScript("return document.body.scrollHeight;");
    // Save the screenshot.
    $this->minkContext->getSession()
          ->getDriver()
          ->resizeWindow(1024, $height, 'current');
    file_put_contents($fileName, $this->minkContext->getSession()->getDriver()->getScreenshot());
  }

  /**
   * @Given I accept cookies
   *
   * @throws Exception
   * Checks if cookie acceptance is shown, and accepts it if it is.
   */
  public function AcceptCookiesMinimizeAskLibrarianOverlay() {
    // We use the searchPage-instance to deal with cookies.
    $this->check($this->searchPage->acceptCookiesMinimizeAskLibrarianOverlay(), $this->searchPage->getMessages());
  }

  /**
   * @param $string - if non-empty - throw an exception
   * @throws Exception
   */
  public function check($result, $msg = '') {
    // Log messages if we have any.
    if ($msg !== '') {
      print_r($msg);
    }
    // Fail if we have a non-empty string.
    if ($result !== "") {
      throw new Exception($result);
    }
  }


  /**
   * @Then I check if the right number of search results are shown
   *
   * The methods "I use facets to reduce..." sets $this->>expectedResultsCount and must
   * be used before this is called, otherwise it will fail with that message.
   */
  public function checkIfTheRightNumberOfSearchResultsAreShown() {
    // Scrape off the search result size from the page. This is displayed on every search result page.
    $resultSize = $this->searchPage->getShownSizeOfSearchResult();
    if ($resultSize < 0) {
      print_r($this->searchPage->getMessages());
      throw new Exception("Couldn't find a search result size on page.");
    }

    // Now compare to the expected number.
    if ($this->searchPage->getExpectedSearchResultSize() != 0 ) {
      if ($this->searchPage->getExpectedSearchResultSize() != $resultSize)
      {
        throw new Exception("Fandt ikke det forventede antal poster. (Fandt: " . $resultSize . ". Forventede:" . $this->searchPage->getExpectedSearchResultSize() . ")");
      }
    }
    else {
      throw new Exception("An expected number was never set. Use facets to set it.");
    }
  }

  /**
   * @Then I check pagination on all pages
   *
   * This is meant only to be used after a multipage search when pageing is in place.
   *
   * It goes through the latest stored search result and finds a random page which is accessible on the
   * currently displayed page, and go to that one.
   * Notice that the way to move to a particular page is to go to the link /search/thing/<searchcrit>?page=n
   * where n = 1 yields page 2 in the search result. (without ?page it defaults to first page)
   * Also notice that not all pages are given as options for the user. On a 10-page result, only 1 + 2 is displayed
   * from the beginning.
   * From page 3 you get "første" + "forrige". On page 1 and 2 you don't.
   *
   *
   *
   */
  public function checkPaginationOnAllPages() {
    $this->check($this->searchPage->checkPaginationOnAllPages(),  $this->searchPage->getMessages());
  }

  /**
   * @Then the search result is sorted on :sortOption
   *
   */
  public function checkSearchResultIsSortedOnSortOption($sortOption) {
    // Check that the user asked for a valid sort-option.
    $this->check($this->searchPage->sortOptionValid($sortOption),  $sortOption );


    $this->check($this->searchPage->checkSorting($sortOption), $this->searchPage->getMessages());
  }

  /**
   * @When I enter :text in field :field
   *
   * Type text character by character, with support for newline, tab as \n and \t
   */
  public function enterTextIntoField($text, $field) {
    $found = $this->getPage()->find('css', $this->translateFieldName($field));
    if (!$found) {
      throw new Exception("Couldn't find the field " . $field);
    }
    $this->scrollTo($found);
    // Click so we place the cursor in the field.
    $found->click();

    /*
     * Now it becomes technical, because we will type each character in the $text variable one at a
     * time, but also we want to use the escape option of f.ex. \n. So we remember if we get the \ char
     * and then check the next character.
     */
    $escaped = false;
    for ($i=0; $i<strlen($text); $i++) {
      $key = substr($text, $i, 1);
      if ($escaped) {
        switch ($key) {
          case 'n':
            $key = "\r\n";
            break;
          case "t":
            $key = "\t";
            break;
          default:
            // We will just let $key be what it is.
        }
      }
      // Unless we start an escaped character, play it through the browser.
      if ($key == "\\") {
        $escaped = true;
      }
      else {
        $this->minkContext->getSession()
              ->getDriver()
              ->getWebDriverSession()
              ->element('xpath', $found->getXpath())
              ->postValue(['value' => [$key]]);
      }
    }
  }

  /**
   * @When I display random object from file
   *
   */
  public function displayRandomObjectFromFile() {
    $mpid = $this->dataMgr->getRandomPID();

    // Help the tester by showing what was searched for and also which test system we're on.
    print_r("Displaying: " . $this->minkContext->getMinkParameter('base_url')  . "ting/object/" . $mpid . "\n");

    // Now open the page - replace the {id} with the mpid in the path.
    $this->objectPage->open(['id' => urlencode($mpid)]);
    $this->waitForPage();
  }


  /**
   * @Then it is possible to add to a list
   * @Then it should be possible to add to a list
   *
   * check for whether the Husk / Tilføj til liste button is shown and visible
   *
   */
  public function findAddToAList() {
    $this->check($this->objectPage->hasAddToList());
  }

  /**
   * @Then it is not possible to add to a list
   *
   * check for whether the Husk / Tilføj til liste button is shown and visible
   *
   */
  public function findAddToListNotPossible() {
    $this->check($this->objectPage->hasNotAddToList());
  }

  /**
   * @Then I (should) see availability options
   *
   * The function can be used to return the href to the image as well.
   */
  public function findAvailabilityOptions() {
    $this->check($this->objectPage->hasAvailabiltyOptions());
  }

  /**
   * @Then I should see a cover page
   */
  public function findCoverPage() {
    $this->check($this->objectPage->hasCoverPage());
  }

  /**
   * @Then it is possible to get online access
   * @Then online access button is shown
   *
   * check for whether the Husk / Tilføj til liste button is shown and visible
   *
   */
  public function findOnlineAccessButton() {
    $this->check($this->objectPage->hasOnlineAccessButton());
  }


  /**
   * @Then there are posts with :attribute in the search results
   */
  public function findPostsWithXXInTheSearchResult($attribute) {
    $this->searchPage->checkPostsWithXXInTheSearchResult($attribute, "some");
  }

  /**
   * @Then all posts have :attribute in the search results
   */
  public function findPostsAllHaveXXInTheSearchResult($attribute) {
    $this->searchPage->checkPostsWithXXInTheSearchResult($attribute, "all");
  }

  /**
   * @When I open a random search result with (a) cover page to show the post
   *
   * Expects to start on a search result. It scans the page for results, chooses one randomly
   * and opens it up by extracting the pid from the link, and force its way to the ting/object/ prefix
   * This means it does not show a work / collection, if that's where the search would go.
   *
   */
  public function findRandomSearchResultWithCoverPageToShowThePost() {
    $this->searchPage->getRandomSearchResultToShowPost("coverpage");
  }

  /**
   * @Then a :relationType entry is shown
   *
   */
  public function findRelationTypeEntryIsShown($relType) {
    $this->check($this->objectPage->entryIsShown($relType));
  }

  /**
   * @Then a :relationType entry is not shown
   *
   */
  public function findRelationTypeEntryNotShown($relType) {
    $this->check($this->objectPage->entryIsNotShown($relType));
  }

  /**
   * @Then it is possible to click to reserve the material
   *
   * checks for the reserve-button being shown and visible
   */
  public function findReserveMaterialButton() {
    $this->check($this->objectPage->hasReservationButton());
  }




  /**
   * @Then I can see :title in the search results first page
   */
  public function findTitleInTheSearchResultsFirstPage($title) {
    $title = $this->translateArgument($title);

    $this->check($this->searchPage->findTitleOnPage($title));
  }

  /**
   * getPage - quick reference to the getPage element. Makes code more readable.
   *
   * @return \Behat\Mink\Element\DocumentElement
   */
  public function getPage() {
    return $this->minkContext->getSession()->getPage();
  }

  /**
   * @Then I am prompted to login
   */
  public function getPromptToLogin() {
     $this->check($this->objectPage->getPromptToLogin());
  }

  /**
   * Navigate to a page.
   *
   * @todo should only navigate if the path is different from the current.
   *
   * @param string $path
   *   The path to navigate to.
   */
  public function gotoPage($path) {
    $this->minkContext->visit($path);
  }


  /**
   * Go to the search page.
   *
   * @Given I have searched for :arg1
   *
   * @param string $string
   *   String to search for.
   * @throws Exception
   */
  public function gotoSearchPage($string) {
    // First we try to translate the argument, to see if there's anything we should pick out first.
    $searchString = $this->translateArgument($string);

    $this->logMsg(($this->searchPage->getVerboseSearchResult()=="on"), "Searches for " . urlencode($searchString) . "\n");

    $this->lastSearchString = $searchString;

    $this->gotoPage('/search/ting/' . urlencode($searchString));

  }



  /**
   * @Given I am logged in as a library user
   * @When I log in as a library user
   */
  public function iAmLoggedInAsALibraryUser() {

    // Temporary solution, setting up hardcoded username list. Password is last 4 for Connie Provider.
    $userlist = array ();
    $userlist[] = 'Lillekvak';
    $userlist[] = 'Supermand';
    $userlist[] = 'Fernando';
    $userlist[] = 'Georgina';
    $userlist[] = 'Henrietta';
    $userlist[] = 'Ibenholt';
    $userlist[] = 'Jepardy';
    $userlist[] = 'Karolina';
    $userlist[] = 'Louisette';
    $userlist[] = 'Marionette';
    $userlist[] = 'Nielsette';
    $userlist[] = 'Ottomand';
    $userlist[] = 'Pegonia';

    // Now pick a random one.
    $name = $userlist[random_int(0, count($userlist)-1)];

    // Set up the user.
    $user = (object) array(
          'name' => $name,
          'pass' => substr($name, -4),
    );
    $this->drupalContext->user = $user;
    $this->login();

    /*
     * We need the user uid for various reasons, however it's not easily
     * available. Apparently the only place it makes an appearance
     * nowadays is in a class on the body element of the user page. So try
     * to dig it out from there.
     */
    $this->drupalContext->getSession()->visit($this->drupalContext->locatePath('/user'));

    $body = $this->getPage()->find('css', 'body');
    if (!$body) {
      throw new Exception("Couldn't find the users own page.");
    }
    $classes = explode(' ', $body->getAttribute('class'));
    foreach ($classes as $class) {
      if (preg_match('{^page-user-(\d+)$}', $class, $matches)) {
        $user->uid = $matches[1];
        break;
      }
    }
    if (!$user->uid) {
      throw new Exception("Couldn't find the users UID from the users page");
    }

    /*
     * In addition, make a note of the "id" that is used in paths (which
     * is most often "me"), so we can construct paths as would be
     * expected. We're sniffing this rather than hardcoding it because
     * some users are except from the "me" replacement.
     */
    $link = $this->drupalContext->getSession()->getPage()->findLink('Brugerprofil');
    if (!$link) {
      throw new Exception("Couldn't find link to user profile on the users page");
    }
    $this->user = $user;
  }

  /**
   * Log a user in.
   *
   */
  public function login() {

    if (!$this->drupalContext->user) {
      throw new \Exception('Tried to login without a user.');
    }

    // It's nice to know in the log who we log in with.
    $this->logMsg(($this->verbose->loginInfo=="on"), "Attempts logging in with user: " . $this->drupalContext->user->name . "\n");

    $this->logTimestamp(($this->verbose->loginInfo=="on"), " - ");

    $el = $this->minkContext->getSession()->getPage();
    if (!$el) {
      throw new Exception("Couldn't find a page to login from");
    }

    // Find out if we are not logged in on page - body has a certain class.
    $pageclass = $el->find('xpath', '//body[contains(@class, "not-logged-in")]');

    if ($pageclass) {
      // Now we know we are not logged-in. Find the link represented by the login-button in the top.
      $xpath = "//body[contains(@class, 'overlay-is-active')]";
      $libutton=$this->getPage()->find('xpath', $xpath);

      if (!$libutton) {

        /*
         * the overlay is not shown. This is expected.
         * So we interject a bit of javascript to open it.
         * This is actually a copy of the js that actually runs on the page itself
         * but I couldn't get to activate that. This seems to do the same thing,
         * except it cannot remove the mobile-tags, so I commented that out.
         */
        $js = "";
        $js .= "document.querySelector('body').classList.toggle('pane-login-is-open');";
        $js .= "if (document.querySelector('body').classList.contains('pane-login-is-open')) {";
        $js .= "document.querySelector('body').classList.add('overlay-is-active');";
        $js .= "} else {";
        $js .= "document.querySelector('body').classList.remove('overlay-is-active');";
        $js .= "}";

        $this->minkContext->getSession()->executeScript($js);
      }
      else {
        throw new Exception("Did not find the login-button");
      }
    }
    else {
      // We are already logged in?! This should not be possible. Yet, here we are.
      print_r("Apparently we are already logged in?");
    }

    // Now wait until the username field is visible - it's the last one that scrolls into view.
    $this->waitUntilFieldIsFound('css', 'input#edit-name', "Login user-name field is not shown");

    // Check if we can see the password and login-button as well.
    $passwordfield = $this->getPage()->find('css', 'input#edit-pass');
    if (!$passwordfield) {
      throw new Exception("Login password field is not shown");
    }
    $loginknap = $this->getPage()->find('css', 'input#edit-submit');
    if (!$loginknap) {
      throw new Exception("Login button is not on page");
    }
    if (!$loginknap->isVisible() || !$passwordfield->isVisible()) {
      throw new Exception("Login button or password field is not shown/accessible on page.");
    }
    // Now fill in credentials.
    $el->fillField($this->drupalContext->getDrupalText('username_field'), $this->drupalContext->user->name);
    $el->fillField($this->drupalContext->getDrupalText('password_field'), $this->drupalContext->user->pass);
    $submit = $el->findButton($this->drupalContext->getDrupalText('log_in'));

    if (empty($submit)) {
      throw new \Exception(sprintf("No login button on page %s", $this->drupalContext->getSession()->getCurrentUrl()));
    }

    // Log in.
    $submit->click();

    // Wait until we can see the username displayed.
    $this->waitUntilFieldIsFound('xpath',
          '//div[contains(@class,"pane-current-user-name")]//div[contains(@class,"pane-content")]/text()[contains(.,"' . $this->drupalContext->user->name . '")]/..',
          "Did not find the users name displayed on page");

    // Check if we are logged in drupal-wise.
    if (!$this->drupalContext->loggedIn()) {
      throw new \Exception(sprintf("Could not log on as user: '%s'", $this->drupalContext->user->name));
    }

    $this->logTimestamp(($this->verbose->loginInfo=="on"), " - OK\n");
  }



  /**
   * log_msg - prints message on log if condition is true.
   *
   * @param bool $ifTrue
   *   indicates if the message is to be printed or not
   * @param string $msg
   *   the actual message to show if condition is true
   */
  public function logMsg($ifTrue, $msg) {
    if ($ifTrue) {
      print_r($msg);
    }
  }

  /**
   * log_timestamp - puts a timestamp in the log. Good for debugging timing issues.
   *
   * @param bool $ifTrue
   *   indicates if the message is to be shown or not
   * @param string $msg
   *   the actual message to show
   */
  public function logTimestamp($ifTrue, $msg) {
    // This is so we can use this function with verbose-checking.
    if ($ifTrue) {
      // Get the microtime, format it and print it.
      $t = microtime(true);
      $micro = sprintf("%06d",($t - floor($t)) * 1000000);
      $d = new DateTime( date('Y-m-d H:i:s.'.$micro, $t));
      print_r($msg . " " . $d->format("Y-m-d H:i:s.u") . "\n");
    }
  }

  /**
   * @Then pageing allows to get all the results
   *
   * This retrieves the search result and stores it in the local array searchResults.
   * There can be several search results, so some garbage collection needs to be done.
   * The searchResult array will be reset before each scenario.
   */
  public function pageingAllowsToGetAllResults() {
    $this->check($this->searchPage->getEntireSearchResult(), ($this->searchPage->getVerboseSearchResult() == "on") ? $this->searchPage->getMessages() : '');
  }

  /**
   * @When I try to reserve the material
   *
   */
  public function reserveTheMaterial() {
    $this->findReserveMaterialButton();

    $this->check($this->objectPage->makeReservation());
  }


  /**
   * @When I scroll to the bottom (of the page)
   *
   * Scroll to bottom of page
   */
  public function scrollToBottom() {
    $found = $this->getPage()->find('css', 'footer.footer');
    if (!$found) {
      $this->scrollTo($found);
    }
  }

  /**
   * @When I scroll :pixels pixels
   *
   * Scroll a bit up
   */
  public function scrollABit($pixels) {
    $this->minkContext->getSession()->executeScript('window.scrollBy(0, ' . $pixels . ');');
  }

  /**
   * Scroll to an element.
   *
   * @param ElementInterface $element
   *   Element to scroll to.
   * @throws \Exception
   *   The exception we throw
   */
  public function scrollTo(ElementInterface $element) {
    // Translate the xpath of the element by adding \\ in front of " to allow it to be passed in the javascript.
    $xpath = strtr($element->getXpath(), ['"' => '\\"']);
    try {
      $js = '';
      $js = $js . 'var el = document.evaluate("' . $xpath .
            '", document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null ).singleNodeValue;';
      $js = $js . 'document.body.scrollTop = document.documentElement.scrollTop = 0;';
      $js = $js . 'el.scrollIntoViewIfNeeded(true);';
      $this->minkContext->getSession()->executeScript($js);
    } catch (UnsupportedDriverActionException $e) {
      // Ignore.
    } catch (Exception $e) {
      throw new Exception('Could not scroll to element: ' . $e->getMessage());
    }
  }

  /**
   * @Given I want a search result between :interval using :listOfTerms published between :publishedInterval
   *
   * Makes a series of searches until the search result is satisfactory.
   * One example of usage is: Given I want a search result between "50-100" using "term.type=bog AND term.publisher=Gyldendal" published between "2000-2017"
   * :interval     = "50-75" lower and upper acceptable number of search results.
   *                         (this is compared to the number of posts found)
   * :listOfTerms  = "term.type=ebog;term.publisher=Gyldendal"
   * :publishedInterval = year interval of publishing, ex. "1995-2017".
   *
   * The method will use all the listOfTerms, but alter the dates published until a satisfactory
   * amount of results are found. It will try first only the earliest year (ex. 1995) and if too
   * few, then it will add another year - and keep adding years until it reaches 2017. If still too
   * few with the full interval, it will fail.
   * If too many, it will move up one year, until it has tried all years.
   * With verbose of searchResults = on it will log it's attempts.
   * If unable to reach a searchresult of the wanted size it will fail.
   *
   */
  public function searchForResultOfCertainSizeUsingInterval($interval, $listOfTerms, $publishedBetween) {
     $this->check($this->searchPage->searchForCertainSize($interval, $listOfTerms, $publishedBetween), $this->searchPage->getMessages());
  }

  /**
   * @When I search on hjemmesiden
   *
   */
  public function searchOnHomePage() {
    $this->check($this->searchPage->searchOnHomePage());
  }

  /**
   * @Given filename :file is used
   *
   */
  public function setFilename($file) {
    $this->dataMgr->setFilename($file);
  }

  /**
   * @When I set (the) number of results per page to :size
   *
   */
  public function setTheNumberOfResultsPerPageToSize($size) {
    $this->check($this->searchPage->setTheNumberOfResultsPerPageToSize($size));
  }


  /**
   * @Given I want verbose mode for :area to be :onoff
   * @Given I set verbose mode for :area to be :onoff
   * @Given I set control mode for :area to be :onoff
   *
   * Sets the control or verbose mode of the run, controlling how much info is put into the output log.
   */
  public function setVerboseControlMode($area, $onoff) {
    $area = mb_strtolower($area);
    $onoff = mb_strtolower($onoff);
    switch($area) {
      // This tells if we want to know the username we logged in with.
      case 'login':
      case 'logininfo':
        $this->verbose->loginInfo = $onoff;
        if ($onoff == 'on') {
          print_r("Verbose mode of loginInfo set to on");
        }
        break;
        // This indicates if we want to see in the log what was found in the searches.
      case 'search-results':
      case 'search-result':
      case 'searchresults':
        $this->searchPage->setVerboseSearchResult($onoff);
        if ($onoff == 'on') {
          print_r("Verbose mode of searchResults set to on");
        }
        break;
        // This indicates if we want to know about handling cookie-popups.
      case 'cookie':
      case 'cookies':
        $this->searchPage->setVerboseCookieMode($onoff);

        if ($onoff == 'on') {
          print_r("Verbose mode of cookie-handling set to on");
        }
        break;
        // This setting controls how many search result pages we will traverse during testing.
      case 'searchmaxpages':

        $this->searchPage->setMaxPageTraversals($onoff);

        // Always notify the user of this setting.
        print_r("Verbose mode for max number of search result pages set to " . $onoff);
        print_r("\n");
        break;
        // This is the catch-all setting.
      case 'everything':
      case 'all':
        $this->verbose->loginInfo = $onoff;
        $this->searchPage->setVerboseSearchResult($onoff);
        $this->searchPage->setVerboseCookieMode($onoff);
        break;
        // If we don't recognise this, let the user know, but don't fail on it.
      default:
        print_r("Unknown verbose mode:" . $area);
        print_r("\n");
        break;
    }
  }

  /**
   * @Given (I) only (want) reservables
   *
   */
  public function setReservables() {
    $this->dataMgr->setReservable(true);
  }

  /**
   * Print out information about the browser being used for the testing
   *
   * @Given you tell me the current browser name
   * @Given you tell me the current browser
   * @Given you show me the current browser name
   * @Given you reveal the browser
   *
   * @param string $path
   *   The path to navigate to.
   */
  public function showTheBrowser() {
    $session = $this->minkContext->getSession();
    $driver = $session->getDriver();
    $userAgent = $driver->evaluateScript('return navigator.userAgent');
    $provider = $driver->evaluateScript('return navigator.vendor');
    $browser = null;
    if (preg_match('/google/i', $provider)) {
      // Using chrome.
      $browser = 'chrome';
    }
    elseif (preg_match('/firefox/i',$userAgent)) {
      $browser = 'firefox';
    }

    if (!$provider) {
      $provider = "<unknown>";
    }
    print_r("The current browser is: " . $browser . "\n");
    print_r("The provider on record is: " . $provider . "\n");
    print_r("The user agent is: " . $userAgent . "\n");

  }

  /**
   * @When I sort the search result on :sortOption
   *
   */
  public function sortTheSearchResultOnOption($sortOption) {
    // Check that the user asked for a valid sort-option.
    $this->check($this->searchPage->sortOptionValid($sortOption));
    $this->check($this->searchPage->sort($sortOption));
  }


  /**
   * Attempts to translate argument given in gherkin script.
   *
   * This allows for generic arguments to be given, to be replaced during runtime here either
   * by looking up values on the current page, or substitute from a catalogue/known variable value
   * The convention is to initiate a variable with a dollar-sign followed by <choice> : <source>
   * choice is the way to select between several values that originates from the source.
   * It could be 'random', 'first', 'last' or simply 'get', if there's only one value possible.
   * Source is pointing to where the value should come from.
   * 'nyhed' looks up news placed on the front page. If not on the front page - this will fail.
   *
   * @param $string
   * @return mixed
   * @throws Exception
   */
  public function translateArgument($string) {
    // If we can't translate it, we just pass it right back.
    $returnString = $string;
    if (substr($string, 0, 1) == "$") {
      // Try to translate it. Form is $ <choice> : <source>!
      $lstr = substr($string, 1);
      $cmdArr = explode(":", $lstr);
      if (count($cmdArr) != 2) {
        throw new Exception("Argument given does not follow \$modifier:source, ex. \$random:news. Got: " . $lstr);
      }

      // Here we try to figure out what to translate to.
      switch (strtolower($cmdArr[1]))
      {
        // Find news (presuming to be on the front page, otherwise fail), choose between them and return the value.
        case "news":
        case 'nyhed':
          $foundArr = $this->getPage()->findAll('css', '.news-text h3.title');
          if (!$foundArr) {
            throw new Exception("Argument for a news item. Could not find any news on the page.");
          }
          // Only first, last and random works with nyheder as choice.
          switch( strtolower($cmdArr[0]))
          {
            case 'first':
              $returnString = $foundArr[0]->getText();
              break;
            case 'last':
              $returnString = $foundArr[count($foundArr)-1]->getText();
              break;
            case 'random':
              $i = random_int(0, count($foundArr)-1);
              $returnString = $foundArr[$i]->getText();
              break;
            default:
              throw new Exception("Only 'first', 'last' og 'random' can be modifiers for 'news'");
          }
          break;
        // Replace the value with the last known search string.
        case 'lastsearchstring':
          // Regardless of the choice.
          $returnString = $this->lastSearchString;
          break;
        default:
          throw new Exception("Unknown \$modifier:source combination: " . $string );
          break;
      }
    }
    if ($returnString != $string) {
      // We always want to tell this, otherwise the tester cannot figure out what was done.
      print_r("Replaced " . $string . " with " . $returnString);
      print_r("\n");
    }
    return $returnString;
  }

  /**
   * Translate popular name to css field name
   *
   * @param string $field
   *   popular name
   * @return string
   *   translated to css field name from popular name. If unknown, input is returned.
   */
  private function translateFieldName($field) {
    $result = $field;
    switch(strtolower($field)) {
      case "søg":
      case "søgefelt":
        $result = "input#edit-search-block-form--2";
        break;
    }
    return $result;
  }

  /**
   * @When I deselect a facet to increase the search results
   *
   * Runs through the facets and deselects one. Note, this will fail if facets have not been selected already
   */
  public function useFacetsToIncreaseSearchResults() {
    // Start by logging what we start out with.
    print_r("Current number of results: " . $this->searchPage->getShownSizeOfSearchResult() . "\n");

    $this->check($this->searchPage->useFacetsToIncreaseSearchResults(), $this->searchPage->getMessages());
  }

  /**
   * @When I use facets to reduce the search results to the highest possible
   *
   * Runs through the facets and selects the highest number of results possible
   */
  public function useFacetsToReduceSearchResultsToTheHighestPossible() {
    // Start by initialising the stack if necessary and reveal which number we are starting with.
    if ($this->searchPage->getExpectedSearchResultSize() < 0) {
      $this->searchPage->setExpectedSearchResultSize($this->searchPage->getShownSizeOfSearchResult());
    }
    print_r("Current number of results: " . $this->searchPage->getShownSizeOfSearchResult() . "\n");

    $this->check( $this->searchPage->useFacetsToReduceSearchResultsToTheHighestPossible(), $this->searchPage->getMessages());
  }


  /**
   * @When I use pagination to go to page :toPage
   *
   * @param int $toPage
   *   is expected to be numeric. First page is 1.
   */
  public function usePaginationToGoToPageN($toPage) {
    // Start by scrolling to the footer so if we fail the screendump will tell us something.
    $this->searchPage->scrollToBottom();

    // This will return the page number.
    $curpg = $this->searchPage->getCurrentPage();

    // Only change page if we are not already on it.
    if ($curpg != $toPage) {
      $this->check($this->searchPage->goToPage($toPage));
    }
    else {
      // We will not fail this.. it may be on purpose.
    }
  }


  /**
   * Wait for page to load.
   */
  public function waitForPage() {
    try {
      // Strictly, this waits for jQuery to be loaded, but it seems sufficient.
      $this->drupalContext->getSession()->wait(5000, 'typeof window.jQuery == "function"');
    } catch (UnsupportedDriverActionException $e) {
      // Ignore.
    } catch (Exception $e) {
      throw new Exception("Unknown error while awaiting page to load:" . $e);
    }
  }

  /**
   * Wait for element to be visible
   */
  public function waitUntilFieldIsFound($locatortype, $locator, $errmsgIfFails) {
    $field = $this->getPage()->find($locatortype, $locator);

    // Timeout is 30 seconds.
    $maxwait = 30;
    while (--$maxwait>0 && !$field ) {
      sleep(1);

      // Try to find it again, if necessary.
      if (!$field) {
        $field = $this->getPage()->find($locatortype, $locator);
      }
    }
    if (!$field) {
      throw new Exception("Waited 30 secs but: " . $errmsgIfFails);
    }
  }

  /**
   * @When waiting up to :waitmax until :txt goes away
   * @param $waitmax - number of waits of 300 ms
   * @param $txt - text that we wait for will disappear
   */
  public function waitUntilTextIsGone($waitmax, $txt) {
    $wait=$this->getPage()->find('xpath', "//text()[contains(.,'" . $txt . "')]/..");
    $continueWaiting = true;
    if (!$wait) {
      return;
    }
    try {
      $continueWaiting = ($wait->isVisible()) ? true : false;

    } catch (Exception $e) {
      // Ignore.
    }
    while ($continueWaiting and --$waitmax>0) {
      usleep(300);
      $wait=$this->getPage()->find('xpath', "//text()[contains(.,'" . $txt . "')]/..");
      if ($wait) {
        try {
          $continueWaiting = ($wait->isVisible()) ? true : false;
        } catch (Exception $e) {
          // Ignore.
        }
      }
      else {
        $continueWaiting = false;
      }
    }
  }
}
