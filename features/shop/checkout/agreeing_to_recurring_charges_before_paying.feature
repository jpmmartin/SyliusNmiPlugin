@paying_with_nmi
Feature: Agreeing to recurring charges before paying
    In order to know that my card will be charged again for renewals of what I am buying
    As a Customer
    I want the pay page to say so before I pay, instead of offering to save my card

    Background:
        Given the store operates on a single channel in "United States"
        And the store has a product "PHP T-Shirt" priced at "$19.99"
        And the store ships everywhere for Free
        And the store has an NMI payment method "Card" with a code "nmi_card" that lets shoppers save their card
        And I am a logged in customer
        And I have a saved "Visa" card ending "1111"

    @ui
    Scenario: Being told before paying that the card is kept for renewals
        Given the store says every payment opens recurring charges
        And I added product "PHP T-Shirt" to the cart
        And I addressed the cart
        And I chose "Free" shipping method and "Card" payment method
        When I check the details of my cart
        And I confirm my order
        Then I should be told my card will be kept for renewals
        And the pay button should say "Pay and keep card for renewals"
        And I should not be offered to save my card
        And I should not be offered my saved cards

    @ui
    Scenario: Paying as before when the payment opens nothing
        Given I added product "PHP T-Shirt" to the cart
        And I addressed the cart
        And I chose "Free" shipping method and "Card" payment method
        When I check the details of my cart
        And I confirm my order
        Then I should not be told anything about renewals
        And the pay button should say "Pay"
        And I should be offered to save my card
        And I should be offered my saved cards
