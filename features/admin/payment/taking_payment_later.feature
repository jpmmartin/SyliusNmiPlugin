@managing_nmi_payment_methods
Feature: Taking payment later
    In order to charge a card once I have decided to, rather than while the shopper is paying
    As an Administrator
    I want an NMI payment method to put the shopper's card on file at checkout instead of charging it

    Background:
        Given the store operates on a single channel in "United States"
        And I am logged in as an administrator

    @ui
    Scenario: Taking payment later is off until I choose it
        When I want to create a new payment method with "NMI" gateway factory
        Then taking payment later should be offered off

    @ui
    Scenario: Choosing to take payment later
        When I want to create a new payment method with "NMI" gateway factory
        And I name it "Card" in "English (United States)"
        And I specify its code as "nmi_card"
        And I set its tokenization key as "tok-public-0123"
        And I set its security key as "sec-private-4567"
        And I set the gateway host to "https://sandbox.nmi.com"
        And I take payment later
        And I add it
        Then I should be notified that it has been successfully created
        And this payment method should take payment later
        And this payment method should charge cards immediately

    @ui
    Scenario: Taking payment later cannot be combined with authorizing first
        When I want to create a new payment method with "NMI" gateway factory
        And I name it "Card" in "English (United States)"
        And I specify its code as "nmi_card"
        And I set its tokenization key as "tok-public-0123"
        And I set its security key as "sec-private-4567"
        And I set the gateway host to "https://sandbox.nmi.com"
        And I enable authorize-then-capture
        And I take payment later
        And I try to add it
        Then I should be notified that taking payment later cannot be used with authorize-then-capture
        And the payment method with name "Card" should not be added
