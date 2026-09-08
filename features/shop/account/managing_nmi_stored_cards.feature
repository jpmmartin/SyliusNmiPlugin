@managing_nmi_stored_cards
Feature: Managing the cards NMI keeps for me
    In order to pay without typing my card again
    As a Customer
    I want to see, choose and remove the cards saved for me

    Background:
        Given the store operates on a single channel in "United States"
        And the store has an NMI payment method "Card" with a code "nmi_card" that lets shoppers save their card
        And there is a customer account "ada@example.com"
        And I am logged in as "ada@example.com"

    @ui
    Scenario: Seeing the cards saved for me
        Given I have a saved "Visa" card ending "1111" that is my default
        And I have a saved "Mastercard" card ending "4242"
        When I browse my saved cards
        Then I should see 2 saved cards
        And I should see a saved card ending "1111"
        And I should see a saved card ending "4242"
        And the card ending "1111" should be my default
        And the card ending "4242" should not be my default

    @ui
    Scenario: Having saved no cards at all
        When I browse my saved cards
        Then I should be told I have no saved cards

    @ui
    Scenario: Choosing a different default
        Given I have a saved "Visa" card ending "1111" that is my default
        And I have a saved "Mastercard" card ending "4242"
        When I browse my saved cards
        And I make the card ending "4242" my default
        Then the card ending "4242" should be my default
        And the card ending "1111" should not be my default

    @ui
    Scenario: Removing a card
        Given I have a saved "Visa" card ending "1111" that is my default
        And I have a saved "Mastercard" card ending "4242"
        When I browse my saved cards
        And I delete the card ending "4242"
        Then I should see 1 saved card
        And I should not see a saved card ending "4242"

    @ui
    Scenario: Removing my default card leaves another one in charge
        Given I have a saved "Visa" card ending "1111" that is my default
        And I have a saved "Mastercard" card ending "4242"
        When I browse my saved cards
        And I delete the card ending "1111"
        Then I should see 1 saved card
        And the card ending "4242" should be my default

    @ui
    Scenario: A card that has expired is shown, and cannot be chosen
        Given I have a saved "Visa" card ending "1111" that is my default
        And I have a saved "Mastercard" card ending "4242" that expired in 01/2020
        When I browse my saved cards
        Then I should see a saved card ending "4242"
        And the card ending "4242" should be shown as expired
        And I should not be able to make the card ending "4242" my default
