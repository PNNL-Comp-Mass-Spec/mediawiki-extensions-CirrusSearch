Feature: Prefix search
  @setup_main
  Scenario Outline: Search suggestions
    Given I am at a random page
    When I type <term> into the search box
    Then suggestions should appear
    And <first_result> is the first suggestion
    And I should be offered to search for <term>
    When I hit enter in the search box
    Then I am on a page titled <title>
  Examples:
    | term                   | first_result           | title                  |
# Note that there are more links to catapult then to any other page that starts with the
# word "catapult" so it should be first
    | catapult               | Catapult               | Catapult               |
    | catapul                | Catapult               | Search results         |
    | two words              | Two Words              | Two Words              |
    | ~catapult              | none                   | Search results         |
    | África                 | África                 | África                 |
# Hitting enter in a search for Africa should pull up África but that bug is beyond me.
    | Africa                 | África                 | Search results         |
    | Template:Template Test | Template:Template Test | Template:Template Test |
